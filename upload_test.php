<?php

require_once 'vendor/autoload.php';
require_once 'S3Utils.php';

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

class S3UploadTester {
    private $s3Utils;
    private $testFiles = [
        'test.txt' => 'This is a test text file content.',
        'test.jpg' => null, // Will be created with random data
        'test.mp4' => null, // Will be created with random data
        'test.pdf' => null  // Will be created with random data
    ];

    public function __construct() {
        $this->s3Utils = new S3Utils();
    }

    private function createTestFiles() {
        echo "Creating test files...\n";

        // Create test directory if it doesn't exist
        if (!file_exists('test_files')) {
            mkdir('test_files', 0777, true);
        }

        foreach ($this->testFiles as $filename => $content) {
            $filepath = 'test_files/' . $filename;

            if ($content === null) {
                // Generate random data for binary files
                $size = 1024 * 1024; // 1MB
                $randomData = openssl_random_pseudo_bytes($size);
                file_put_contents($filepath, $randomData);
            } else {
                file_put_contents($filepath, $content);
            }

            echo "Created: $filepath\n";
        }
    }

    private function cleanupTestFiles() {
        echo "\nCleaning up test files...\n";
        foreach ($this->testFiles as $filename => $_) {
            $filepath = 'test_files/' . $filename;
            if (file_exists($filepath)) {
                unlink($filepath);
                echo "Deleted: $filepath\n";
            }
        }

        // Remove test directory if empty
        if (is_dir('test_files') && count(glob('test_files/*')) === 0) {
            rmdir('test_files');
            echo "Removed test_files directory\n";
        }
    }

    public function runTests() {
        try {
            $this->createTestFiles();
            echo "\nStarting upload tests...\n\n";

            // Test 1: Upload text file
            echo "Test 1: Uploading text file...\n";
            $textFileUrl = $this->s3Utils->uploadFile(
                'test_files/test.txt',
                'test_uploads/test.txt',
                'text/plain'
            );
            echo "Text file uploaded to: $textFileUrl\n\n";

            // Test 2: Upload image file
            echo "Test 2: Uploading image file...\n";
            $imageFileUrl = $this->s3Utils->uploadFile(
                'test_files/test.jpg',
                'test_uploads/test.jpg',
                'image/jpeg'
            );
            echo "Image file uploaded to: $imageFileUrl\n\n";

            // Test 3: Upload video file
            echo "Test 3: Uploading video file...\n";
            $videoFileUrl = $this->s3Utils->uploadFile(
                'test_files/test.mp4',
                'test_uploads/test.mp4',
                'video/mp4'
            );
            echo "Video file uploaded to: $videoFileUrl\n\n";

            // Test 4: Upload PDF file
            echo "Test 4: Uploading PDF file...\n";
            $pdfFileUrl = $this->s3Utils->uploadFile(
                'test_files/test.pdf',
                'test_uploads/test.pdf',
                'application/pdf'
            );
            echo "PDF file uploaded to: $pdfFileUrl\n\n";

            // Test 5: Upload with string content
            echo "Test 5: Uploading string content...\n";
            $stringContentUrl = $this->s3Utils->uploadFile(
                'This is a test string content',
                'test_uploads/string_content.txt',
                'text/plain'
            );
            echo "String content uploaded to: $stringContentUrl\n\n";

            // Test 6: Upload with size limit
            echo "Test 6: Testing file size limit...\n";
            $sizeLimitUrl = $this->s3Utils->uploadFile(
                'test_files/test.txt',
                'test_uploads/size_test.txt',
                'text/plain',
                true,
                100 // 100 bytes limit
            );
            if ($sizeLimitUrl) {
                echo "File uploaded within size limit to: $sizeLimitUrl\n";
            } else {
                echo "File size limit test passed (upload rejected as expected)\n";
            }

            echo "\nAll tests completed!\n";

        } catch (\Exception $e) {
            echo "Error during tests: " . $e->getMessage() . "\n";
        } finally {
            $this->cleanupTestFiles();
        }
    }
}

// Run the tests if executed directly
if (php_sapi_name() === 'cli') {
    $tester = new S3UploadTester();
    $tester->runTests();
} else {
    echo "This script should be run from the command line.\n";
}