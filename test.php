<?php 
	require(__DIR__ . '/vendor/autoload.php');
	require(__DIR__ . '/fembed.class.php');
	$fembed = new FembedUpload();
	$fembed->SetInput(__DIR__ .'/video.mp4');
	$account = (object) ['client_id' => '123374', 'client_secret' => '566325f271a983csfbf9724s477e6e415767'];
	$fembed->SetAccount($account);
	var_dump($fembed->run());
