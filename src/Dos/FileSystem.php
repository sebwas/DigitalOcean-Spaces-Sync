<?php

namespace Dos;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\FileSystem as FlySystem;

final class FileSystem {
	public static function getInstance ($key, $secret, $container, $endpoint) {
		$client = S3Client::factory([
			'credentials' => [
				'key'    => $key,
				'secret' => $secret,
			],
			'bucket'   => 'do-spaces',
			'endpoint' => $endpoint,
			'version'  => 'latest',
			// region means nothing for DO Spaces, but aws client may drop and error without it
			'region' => 'us-east-1',
		]);

		$connection = new AwsS3Adapter($client, $container);
		$filesystem = new FlySystem($connection);

		return $filesystem;
	}
}
