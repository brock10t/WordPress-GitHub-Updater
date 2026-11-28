<?php
/** Validated payload fingerprints vs real disposable staged trees. @package TenTGitHubUpdater */
namespace TenT\GitHubUpdater\Packages {
	function hrtime( $number ) { $GLOBALS['stage_clock'] += $GLOBALS['stage_clock_step']; return $GLOBALS['stage_clock'] * 1000000000; }
}
namespace {
	use TenT\GitHubUpdater\Packages\Staged_Files;
	use TenT\GitHubUpdater\Packages\Zip_Contents;
	use TenT\GitHubUpdater\Registry\Registration;
	require __DIR__ . '/support/zip-environment.php';
	require dirname(__DIR__) . '/includes/packages/class-zip-contents.php';
	require dirname(__DIR__) . '/includes/packages/class-staged-files.php';
	$stage_clock = 1000.0; $stage_clock_step = 0.0;
	set_error_handler(function($severity,$message,$file,$line){throw new \ErrorException($message,0,$severity,$file,$line);});
	$base = sys_get_temp_dir().'/10t-stage-'.bin2hex(random_bytes(6)); mkdir($base);
	define('WP_PLUGIN_DIR',$base.'/installed');mkdir(WP_PLUGIN_DIR);
	$working=$base.'/working';mkdir($working);mkdir($working.'/fixture');mkdir($working.'/fixture/sub');
	$zip=$base.'/input.zip';$record=new Registration('fixture/main.php','owner','repository');$checker=new Staged_Files();
	$source="<?php // DO_NOT_EXECUTE\n";$binary="\x00\xffbinary\0";
	file_put_contents($working.'/fixture/main.php',$source);file_put_contents($working.'/fixture/sub/data',$binary);file_put_contents($working.'/fixture/empty','');
	$entries=array(array('name'=>'fixture/main.php','body'=>$source),array('name'=>'fixture/sub/data','body'=>$binary),array('name'=>'fixture/empty','body'=>'','method'=>0));file_put_contents($zip,zip_bytes($entries));
	function stage_error($result,string $suffix=''):void{assert_zip($result instanceof WP_Error&&(''===$suffix||'github_updater_staged_'.$suffix===$result->code),'Expected staged failure '.$suffix);}
	try {
		$large=str_repeat('multi-chunk binary '."\0",8192);
		file_put_contents($working.'/fixture/large.bin',$large);
		file_put_contents($zip,zip_bytes(array_merge($entries,array(array('name'=>'fixture/large.bin','body'=>$large,'method'=>0)))));
		assert_zip(true===$checker->check($zip,$working,$record),'Large stored file hashed across bounded read chunks');
		file_put_contents($working.'/fixture/large.bin',substr($large,0,-1).'x');stage_error($checker->check($zip,$working,$record),'mismatch');unlink($working.'/fixture/large.bin');unset($large);
		file_put_contents($zip,zip_bytes($entries));
		$before=hash_file('sha256',$zip);
		assert_zip(true===$checker->check($zip,$working,$record),'Exact tree with implicit directories and empty/binary files');
		$fingerprints=(new Zip_Contents())->fingerprints($zip,$record);
		assert_zip(hash('sha256',$binary)===$fingerprints['fixture/sub/data']['sha256']&&strlen($binary)===$fingerprints['fixture/sub/data']['size'],'Fingerprints hash actual complete payload bytes');
		assert_zip(hash('sha256','')===$fingerprints['fixture/empty']['sha256'],'Empty file digest');
		assert_zip($before===hash_file('sha256',$zip),'ZIP unchanged');
		file_put_contents($working.'/fixture/main.php',str_repeat('x',strlen($source)));stage_error($checker->check($zip,$working,$record),'mismatch');file_put_contents($working.'/fixture/main.php',$source);
		file_put_contents($working.'/fixture/extra','x');stage_error($checker->check($zip,$working,$record),'mismatch');unlink($working.'/fixture/extra');
		unlink($working.'/fixture/empty');stage_error($checker->check($zip,$working,$record),'mismatch');file_put_contents($working.'/fixture/empty','');
		rename($working.'/fixture/main.php',$working.'/fixture/Main.php');stage_error($checker->check($zip,$working,$record),'mismatch');rename($working.'/fixture/Main.php',$working.'/fixture/main.php');
		file_put_contents($working.'/fixture/empty','one');stage_error($checker->check($zip,$working,$record));file_put_contents($working.'/fixture/empty','');
		unlink($working.'/fixture/empty');symlink($working.'/fixture/main.php',$working.'/fixture/empty');stage_error($checker->check($zip,$working,$record),'type');unlink($working.'/fixture/empty');file_put_contents($working.'/fixture/empty','');
		unlink($working.'/fixture/empty');link($working.'/fixture/main.php',$working.'/fixture/empty');stage_error($checker->check($zip,$working,$record),'type');unlink($working.'/fixture/empty');file_put_contents($working.'/fixture/empty','');
		rename($working.'/fixture/sub',$base.'/saved-sub');symlink($base.'/saved-sub',$working.'/fixture/sub');stage_error($checker->check($zip,$working,$record),'type');unlink($working.'/fixture/sub');rename($base.'/saved-sub',$working.'/fixture/sub');
		if(function_exists('posix_mkfifo')){unlink($working.'/fixture/empty');posix_mkfifo($working.'/fixture/empty',0600);stage_error($checker->check($zip,$working,$record),'type');unlink($working.'/fixture/empty');file_put_contents($working.'/fixture/empty','');}
		chmod($working.'/fixture/main.php',04755);stage_error($checker->check($zip,$working,$record),'type');chmod($working.'/fixture/main.php',0644);
		stage_error($checker->check($zip,WP_PLUGIN_DIR,$record),'root');stage_error($checker->check($zip,$base,$record),'root');
		symlink($working,$base.'/linked');stage_error($checker->check($zip,$base.'/linked',$record),'root');unlink($base.'/linked');
		$dir=array('name'=>'fixture/sub/','body'=>'','method'=>0,'external'=>(0040755<<16)|0x10);
		file_put_contents($zip,zip_bytes(array_merge($entries,array($dir))));assert_zip(true===$checker->check($zip,$working,$record),'Explicit directory after children matches implicit staged directory');
		file_put_contents($zip,zip_bytes(array(array('body'=>$source,'local'=>array('crc'=>1),'central'=>array('crc'=>1)))));$result=$checker->check($zip,$working,$record);assert_zip($result instanceof WP_Error&&'github_updater_zip_content_crc'===$result->code,'Corrupt ZIP cannot supply trusted hashes');
		file_put_contents($zip,zip_bytes(array(array('body'=>$source,'method'=>0))));$stage_clock_step=15.0;stage_error($checker->check($zip,$working,$record),'timeout');$stage_clock_step=0.0;
		// Many implicit parent directories also count toward staged node limit.
		$many=array(array('body'=>$source));for($i=0;$i<2500;++$i){$many[]=array('name'=>'fixture/d'.$i.'/file','body'=>'');}file_put_contents($zip,zip_bytes($many));stage_error($checker->check($zip,$working,$record),'limits');
		echo "PASS: complete staged-tree SHA-256 matching, missing/extra/case/content/type/link failures, boundaries and ZIP integrity prerequisites; no source modifications.\n";
	} finally {
		unlink($working.'/fixture/sub/data');rmdir($working.'/fixture/sub');unlink($working.'/fixture/main.php');unlink($working.'/fixture/empty');rmdir($working.'/fixture');rmdir($working);rmdir(WP_PLUGIN_DIR);unlink($zip);rmdir($base);
	}
}
