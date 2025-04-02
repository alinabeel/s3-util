<?php

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Dotenv\Dotenv;

class S3Utils {
    private $s3Client;
    private $bucket;
    private $videoContentTypes = [
        '.mp4' => 'video/mp4',
        '.avi' => 'video/x-msvideo',
        '.mov' => 'video/quicktime',
        '.wmv' => 'video/x-ms-wmv',
        '.flv' => 'video/x-flv',
        '.webm' => 'video/webm',
        '.mkv' => 'video/x-matroska',
        '.m4v' => 'video/x-m4v',
        '.3gp' => 'video/3gpp',
        '.ts' => 'video/mp2t'
    ];

    public function __construct() {
        // Load environment variables from .env file
        $dotenv = Dotenv::createImmutable(__DIR__);
        $dotenv->load();

        // Validate required environment variables
        $dotenv->required([
            'AWS_ACCESS_KEY_ID',
            'AWS_SECRET_ACCESS_KEY',
            'AWS_DEFAULT_REGION',
            'AWS_BUCKET',
            'AWS_URL'
        ]);

        // Get the path to cacert.pem
        $cacertPath = __DIR__ . '/vendor/guzzlehttp/guzzle/src/Handler/cacert.pem';

        // If cacert.pem doesn't exist in the vendor directory, try to find it in the system
        if (!file_exists($cacertPath)) {
            $cacertPath = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
        }

        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region'  => $_ENV['AWS_DEFAULT_REGION'],
            'credentials' => [
                'key'    => $_ENV['AWS_ACCESS_KEY_ID'],
                'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'],
            ],
            'http' => [
                'verify' => $cacertPath
            ]
        ]);
        $this->bucket = $_ENV['AWS_BUCKET'];
    }

    public function getS3Client() {
        return $this->s3Client;
    }

    private function getContentType($filename) {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Check video content types
        if (isset($this->videoContentTypes['.' . $extension])) {
            return $this->videoContentTypes['.' . $extension];
        }

        // Check other common types
        $commonTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'pdf' => 'application/pdf'
        ];

        if (isset($commonTypes[$extension])) {
            return $commonTypes[$extension];
        }

        // Default to binary if no specific type is found
        return 'application/octet-stream';
    }

    public function uploadFile($fileObj, $s3Key, $contentType = null, $public = true, $maxFileSize = null) {
        try {
            // Check file size if max_file_size is specified
            if ($maxFileSize !== null) {
                if (is_string($fileObj) && file_exists($fileObj)) {
                    $fileSize = filesize($fileObj);
                } elseif (is_string($fileObj)) {
                    $fileSize = strlen($fileObj);
                } else {
                    // For file-like objects, try to get size
                    try {
                        $fileSize = fstat($fileObj)['size'];
                    } catch (\Exception $e) {
                        error_log("Could not determine file size");
                        $fileSize = null;
                    }
                }

                if ($fileSize && $fileSize > $maxFileSize) {
                    error_log("File size ($fileSize bytes) exceeds maximum allowed size ($maxFileSize bytes)");
                    return null;
                }
            }

            // Determine content type if not provided
            if (!$contentType) {
                $contentType = $this->getContentType($s3Key);
            }

            $extraArgs = ['ContentType' => $contentType];

            // Handle different input types
            if (is_string($fileObj) && file_exists($fileObj)) {
                $this->s3Client->putObject([
                    'Bucket' => $this->bucket,
                    'Key'    => $s3Key,
                    'SourceFile' => $fileObj,
                    'ContentType' => $contentType
                ]);
            } elseif (is_string($fileObj)) {
                $this->s3Client->putObject([
                    'Bucket' => $this->bucket,
                    'Key'    => $s3Key,
                    'Body'   => $fileObj,
                    'ContentType' => $contentType
                ]);
            } else {
                $this->s3Client->putObject([
                    'Bucket' => $this->bucket,
                    'Key'    => $s3Key,
                    'Body'   => $fileObj,
                    'ContentType' => $contentType
                ]);
            }

            // Return the URL of the uploaded file
            return $_ENV['AWS_URL'] . '/' . $s3Key;

        } catch (AwsException $e) {
            error_log("Error uploading file to S3: " . $e->getMessage());
            return null;
        } catch (\Exception $e) {
            error_log("Unexpected error uploading file to S3: " . $e->getMessage());
            return null;
        }
    }

    public function deleteFile($s3Key) {
        try {
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => $s3Key
            ]);
            return true;
        } catch (AwsException $e) {
            error_log("Error deleting file from S3: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log("Unexpected error deleting file from S3: " . $e->getMessage());
            return false;
        }
    }

    public function generatePresignedUrl($s3Key, $expiration = 3600, $httpMethod = 'get') {
        try {
            $cmd = $this->s3Client->getCommand(
                $httpMethod === 'get' ? 'GetObject' : 'PutObject',
                [
                    'Bucket' => $this->bucket,
                    'Key'    => $s3Key
                ]
            );

            $request = $this->s3Client->createPresignedRequest($cmd, "+$expiration seconds");
            return (string) $request->getUri();
        } catch (AwsException $e) {
            error_log("Error generating presigned URL: " . $e->getMessage());
            return null;
        }
    }

    public function listDirectory($prefix = '', $delimiter = '/', $maxKeys = 1000) {
        try {
            $params = [
                'Bucket' => $this->bucket,
                'MaxKeys' => $maxKeys
            ];

            // Add prefix if provided
            if (!empty($prefix)) {
                $params['Prefix'] = $prefix;
            }

            // Add delimiter if provided
            if (!empty($delimiter)) {
                $params['Delimiter'] = $delimiter;
            }

            $result = $this->s3Client->listObjects($params);

            $contents = [
                'files' => [],
                'directories' => [],
                'next_marker' => $result['NextMarker'] ?? null
            ];

            // Process files
            if (isset($result['Contents'])) {
                foreach ($result['Contents'] as $item) {
                    // Skip the prefix itself if it's a directory
                    if ($item['Key'] === $prefix) {
                        continue;
                    }

                    $contents['files'][] = [
                        'key' => $item['Key'],
                        'size' => $item['Size'],
                        'last_modified' => $item['LastModified'],
                        'content_type' => $this->getContentType($item['Key'])
                    ];
                }
            }

            // Process directories (CommonPrefixes)
            if (isset($result['CommonPrefixes'])) {
                foreach ($result['CommonPrefixes'] as $prefix) {
                    $contents['directories'][] = $prefix['Prefix'];
                }
            }

            return $contents;

        } catch (AwsException $e) {
            error_log("Error listing S3 directory: " . $e->getMessage());
            return null;
        } catch (\Exception $e) {
            error_log("Unexpected error listing S3 directory: " . $e->getMessage());
            return null;
        }
    }
}