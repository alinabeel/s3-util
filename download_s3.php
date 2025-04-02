<?php

require_once 'vendor/autoload.php';
require_once 'S3Utils.php';

use Dotenv\Dotenv;
use parallel\Runtime;
use parallel\Channel;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

class S3Downloader {
    private $s3Utils;
    private $localBasePath;
    private $totalFiles;
    private $processedFiles;
    private $skippedFiles;

    public function __construct($localBasePath = 'downloads') {
        $this->s3Utils = new S3Utils();
        $this->localBasePath = $localBasePath;
        $this->processedFiles = 0;
        $this->skippedFiles = 0;

        // Create base directory if it doesn't exist
        if (!file_exists($localBasePath)) {
            mkdir($localBasePath, 0777, true);
        }
    }

    private function updateProgress($s3Key, $status) {
        $this->processedFiles++;
        $progress = round(($this->processedFiles / $this->totalFiles) * 100, 1);
        echo sprintf(
            "\rProgress: %d%% (%d/%d files) - %s: %s",
            $progress,
            $this->processedFiles,
            $this->totalFiles,
            $status,
            $s3Key
        );
    }

    public function downloadFile($s3Key, $localPath = null) {
        try {
            // If local path is not provided, use the S3 key as the path
            if ($localPath === null) {
                $localPath = $s3Key;
            }

            // Generate full local path
            $fullLocalPath = $this->localBasePath . '/' . $localPath;

            // Check if file already exists
            if (file_exists($fullLocalPath)) {
                $this->updateProgress($s3Key, "SKIPPED");
                $this->skippedFiles++;
                return true;
            }

            // Create directory structure if it doesn't exist
            $directory = dirname($fullLocalPath);
            if (!file_exists($directory)) {
                mkdir($directory, 0777, true);
            }

            // Get presigned URL for the file
            $presignedUrl = $this->s3Utils->generatePresignedUrl($s3Key);
            if (!$presignedUrl) {
                $this->updateProgress($s3Key, "FAILED");
                error_log("Failed to generate presigned URL for: $s3Key");
                return false;
            }

            // Set up SSL context for file_get_contents
            $opts = [
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false
                ]
            ];

            // Download the file
            $fileContent = file_get_contents($presignedUrl, false, stream_context_create($opts));
            if ($fileContent === false) {
                $this->updateProgress($s3Key, "FAILED");
                error_log("Failed to download file from: $presignedUrl");
                return false;
            }

            // Save the file
            if (file_put_contents($fullLocalPath, $fileContent) === false) {
                $this->updateProgress($s3Key, "FAILED");
                error_log("Failed to save file to: $fullLocalPath");
                return false;
            }

            $this->updateProgress($s3Key, "SUCCESS");
            return true;

        } catch (\Exception $e) {
            $this->updateProgress($s3Key, "ERROR");
            error_log("Error downloading file: " . $e->getMessage());
            return false;
        }
    }

    public function downloadDirectory($s3Prefix, $localPath = null, $filePrefix = null, $filePostfix = null) {
        try {
            // If local path is not provided, use the S3 prefix as the path
            if ($localPath === null) {
                $localPath = $s3Prefix;
            }

            // Create the local directory
            $fullLocalPath = $this->localBasePath . '/' . $localPath;
            if (!file_exists($fullLocalPath)) {
                mkdir($fullLocalPath, 0777, true);
            }

            // List objects in the S3 directory with pagination
            $s3Client = $this->s3Utils->getS3Client();
            $bucket = $_ENV['AWS_BUCKET'];

            // First, count total files
            $this->totalFiles = 0;
            $nextMarker = null;

            do {
                $params = [
                    'Bucket' => $bucket,
                    'Prefix' => $s3Prefix,
                    'MaxKeys' => 1000
                ];

                if ($nextMarker) {
                    $params['Marker'] = $nextMarker;
                }

                $objects = $s3Client->listObjects($params);

                foreach ($objects['Contents'] as $object) {
                    if (substr($object['Key'], -1) !== '/') {
                        $filename = basename($object['Key']);
                        // Check both prefix and postfix if specified
                        $matchesPrefix = $filePrefix === null || strpos($filename, $filePrefix) === 0;
                        $matchesPostfix = $filePostfix === null || substr($filename, -strlen($filePostfix)) === $filePostfix;

                        if ($matchesPrefix && $matchesPostfix) {
                            $this->totalFiles++;
                        }
                    }
                }

                $nextMarker = $objects['IsTruncated'] ? end($objects['Contents'])['Key'] : null;
            } while ($nextMarker);

            if ($this->totalFiles === 0) {
                echo "No matching files found in directory.\n";
                return true;
            }

            echo "Found {$this->totalFiles} matching files to download...\n";

            $successCount = 0;
            $failureCount = 0;
            $nextMarker = null;

            // Download files in batches
            do {
                $params = [
                    'Bucket' => $bucket,
                    'Prefix' => $s3Prefix,
                    'MaxKeys' => 1000
                ];

                if ($nextMarker) {
                    $params['Marker'] = $nextMarker;
                }

                $objects = $s3Client->listObjects($params);

                foreach ($objects['Contents'] as $object) {
                    $s3Key = $object['Key'];

                    // Skip if it's a directory marker
                    if (substr($s3Key, -1) === '/') {
                        continue;
                    }

                    $filename = basename($s3Key);
                    // Check both prefix and postfix if specified
                    $matchesPrefix = $filePrefix === null || strpos($filename, $filePrefix) === 0;
                    $matchesPostfix = $filePostfix === null || substr($filename, -strlen($filePostfix)) === $filePostfix;

                    if (!$matchesPrefix || !$matchesPostfix) {
                        continue;
                    }

                    // Calculate relative path
                    $relativePath = substr($s3Key, strlen($s3Prefix));
                    $localFilePath = $localPath . '/' . $relativePath;

                    if ($this->downloadFile($s3Key, $localFilePath)) {
                        $successCount++;
                    } else {
                        $failureCount++;
                    }
                }

                $nextMarker = $objects['IsTruncated'] ? end($objects['Contents'])['Key'] : null;
            } while ($nextMarker);

            echo "\n\nDownload Summary:\n";
            echo "Total matching files: {$this->totalFiles}\n";
            echo "Successfully downloaded: {$successCount}\n";
            echo "Skipped (already exists): {$this->skippedFiles}\n";
            echo "Failed: {$failureCount}\n";

            return $failureCount === 0;

        } catch (\Exception $e) {
            error_log("Error downloading directory: " . $e->getMessage());
            return false;
        }
    }

    public function deleteFromS3($s3Key, $isDirectory = false) {
        try {
            $s3Client = $this->s3Utils->getS3Client();
            $bucket = $_ENV['AWS_BUCKET'];

            if ($isDirectory) {
                // For directories, we need to delete all objects with the prefix
                $nextMarker = null;
                $deletedCount = 0;
                $failedCount = 0;

                do {
                    $params = [
                        'Bucket' => $bucket,
                        'Prefix' => $s3Key,
                        'MaxKeys' => 1000
                    ];

                    if ($nextMarker) {
                        $params['Marker'] = $nextMarker;
                    }

                    $objects = $s3Client->listObjects($params);

                    if (empty($objects['Contents'])) {
                        echo "No files found in directory.\n";
                        return true;
                    }

                    // Delete objects in batches of 1000 (S3 limit)
                    $objectsToDelete = [];
                    foreach ($objects['Contents'] as $object) {
                        $objectsToDelete[] = [
                            'Key' => $object['Key']
                        ];
                    }

                    if (!empty($objectsToDelete)) {
                        $result = $s3Client->deleteObjects([
                            'Bucket' => $bucket,
                            'Delete' => [
                                'Objects' => $objectsToDelete
                            ]
                        ]);

                        if (isset($result['Deleted'])) {
                            $deletedCount += count($result['Deleted']);
                        }
                        if (isset($result['Errors'])) {
                            $failedCount += count($result['Errors']);
                        }
                    }

                    $nextMarker = $objects['IsTruncated'] ? end($objects['Contents'])['Key'] : null;
                } while ($nextMarker);

                echo "Delete Summary:\n";
                echo "Successfully deleted: {$deletedCount} files\n";
                echo "Failed to delete: {$failedCount} files\n";

                return $failedCount === 0;
            } else {
                // For single file
                $result = $s3Client->deleteObject([
                    'Bucket' => $bucket,
                    'Key' => $s3Key
                ]);

                if ($result) {
                    echo "Successfully deleted file: {$s3Key}\n";
                    return true;
                } else {
                    echo "Failed to delete file: {$s3Key}\n";
                    return false;
                }
            }
        } catch (\Exception $e) {
            error_log("Error deleting from S3: " . $e->getMessage());
            return false;
        }
    }
}

// Example usage
if (php_sapi_name() === 'cli') {
    // Command line interface
    if ($argc < 2) {
        echo "Usage:\n";
        echo "  List directory: php download_s3.php list [prefix] [--max-keys=N]\n";
        echo "  Download file: php download_s3.php download <s3_key> [local_path]\n";
        echo "  Download directory: php download_s3.php download-dir <s3_prefix> [local_path] [--prefix=file_prefix] [--postfix=file_postfix]\n";
        echo "  Delete file: php download_s3.php delete <s3_key>\n";
        echo "  Delete directory: php download_s3.php delete-dir <s3_prefix>\n";
        exit(1);
    }

    $command = $argv[1];
    $s3Utils = new S3Utils();

    switch ($command) {
        case 'list':
            $prefix = $argv[2] ?? '';
            $maxKeys = 1000;

            // Parse --max-keys option if provided
            for ($i = 2; $i < $argc; $i++) {
                if (strpos($argv[$i], '--max-keys=') === 0) {
                    $maxKeys = (int) substr($argv[$i], 11);
                    break;
                }
            }

            $contents = $s3Utils->listDirectory($prefix, '/', $maxKeys);

            if ($contents === null) {
                echo "Failed to list directory contents.\n";
                exit(1);
            }

            echo "\nDirectories:\n";
            foreach ($contents['directories'] as $dir) {
                echo "  " . $dir . "\n";
            }

            echo "\nFiles:\n";
            foreach ($contents['files'] as $file) {
                $size = round($file['size'] / (1024 * 1024), 2); // Convert bytes to MB
                $date = $file['last_modified']->format('Y-m-d H:i:s');
                echo "  {$file['key']} ({$size} MB, {$date})\n";
            }

            if ($contents['next_marker']) {
                echo "\nMore items available. Use --max-keys=N to see more.\n";
            }
            break;

        case 'download':
            if ($argc < 3) {
                echo "Usage: php download_s3.php download <s3_key> [local_path]\n";
                exit(1);
            }

            $s3Key = $argv[2];
            $localPath = $argv[3] ?? null;

            $downloader = new S3Downloader();
            $success = $downloader->downloadFile($s3Key, $localPath);

            if ($success) {
                echo "Download completed successfully.\n";
            } else {
                echo "Download failed. Check error logs for details.\n";
                exit(1);
            }
            break;

        case 'download-dir':
            if ($argc < 3) {
                echo "Usage: php download_s3.php download-dir <s3_prefix> [local_path] [--prefix=file_prefix] [--postfix=file_postfix]\n";
                exit(1);
            }

            $s3Prefix = $argv[2];
            $localPath = $argv[3] ?? null;
            $filePrefix = null;
            $filePostfix = null;

            // Parse options if provided
            for ($i = 3; $i < $argc; $i++) {
                if (strpos($argv[$i], '--prefix=') === 0) {
                    $filePrefix = substr($argv[$i], 9);
                } elseif (strpos($argv[$i], '--postfix=') === 0) {
                    $filePostfix = substr($argv[$i], 10);
                }
            }

            $downloader = new S3Downloader();
            $success = $downloader->downloadDirectory($s3Prefix, $localPath, $filePrefix, $filePostfix);

            if ($success) {
                echo "Directory download completed successfully.\n";
            } else {
                echo "Directory download failed. Check error logs for details.\n";
                exit(1);
            }
            break;

        case 'delete':
            if ($argc < 3) {
                echo "Usage: php download_s3.php delete <s3_key>\n";
                exit(1);
            }

            $s3Key = $argv[2];
            $downloader = new S3Downloader();
            $success = $downloader->deleteFromS3($s3Key, false);

            if (!$success) {
                echo "Delete failed. Check error logs for details.\n";
                exit(1);
            }
            break;

        case 'delete-dir':
            if ($argc < 3) {
                echo "Usage: php download_s3.php delete-dir <s3_prefix>\n";
                exit(1);
            }

            $s3Prefix = $argv[2];
            $downloader = new S3Downloader();
            $success = $downloader->deleteFromS3($s3Prefix, true);

            if (!$success) {
                echo "Directory delete failed. Check error logs for details.\n";
                exit(1);
            }
            break;

        default:
            echo "Unknown command: $command\n";
            exit(1);
    }
} else {
    // Web interface example
    $downloader = new S3Downloader();

    // Example: Download a single file
    $success = $downloader->downloadFile('path/to/file.mp4', 'videos/file.mp4');

    // Example: Download a directory
    $success = $downloader->downloadDirectory('videos/', 'local_videos');

    // Example: Delete a file
    $success = $downloader->deleteFromS3('path/to/file.mp4', false);

    // Example: Delete a directory
    $success = $downloader->deleteFromS3('videos/', true);
}


