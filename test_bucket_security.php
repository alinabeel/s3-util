<?php

require_once 'vendor/autoload.php';
require_once 'S3Utils.php';

use Dotenv\Dotenv;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class BucketSecurityTester {
    private $bucket;
    private $region;
    private $results = [];
    private $totalTests = 0;
    private $passedTests = 0;

    // ANSI color codes
    private const GREEN = "\033[32m";
    private const RED = "\033[31m";
    private const YELLOW = "\033[33m";
    private const BLUE = "\033[34m";
    private const RESET = "\033[0m";
    private const BOLD = "\033[1m";

    public function __construct() {
        // Load environment variables
        $dotenv = Dotenv::createImmutable(__DIR__);
        $dotenv->load();

        $this->bucket = $_ENV['AWS_BUCKET'];
        $this->region = $_ENV['AWS_DEFAULT_REGION'];
    }

    private function printHeader() {
        echo self::BOLD . self::BLUE . "\n" . str_repeat("=", 60) . "\n";
        echo "S3 BUCKET SECURITY TEST RESULTS\n";
        echo str_repeat("=", 60) . "\n" . self::RESET;
    }

    private function printFooter() {
        $percentage = ($this->passedTests / $this->totalTests) * 100;
        $color = $percentage == 100 ? self::GREEN : self::RED;

        echo self::BOLD . self::BLUE . "\n" . str_repeat("=", 60) . "\n";
        echo "SUMMARY\n";
        echo str_repeat("=", 60) . "\n" . self::RESET;

        echo "Total Tests: " . $this->totalTests . "\n";
        echo "Passed: " . $color . $this->passedTests . self::RESET . "\n";
        echo "Failed: " . ($this->totalTests - $this->passedTests) . "\n";
        echo "Success Rate: " . $color . round($percentage, 2) . "%" . self::RESET . "\n\n";
    }

    private function printTestResult($testNumber, $testName, $passed, $message) {
        $this->totalTests++;
        if ($passed) $this->passedTests++;

        $status = $passed ?
            self::GREEN . "✓ PASSED" :
            self::RED . "✗ FAILED";

        echo self::BOLD . "Test {$testNumber}: {$testName}\n" . self::RESET;
        echo "Status: " . $status . self::RESET . "\n";
        echo "Message: " . $message . "\n";
        echo str_repeat("-", 60) . "\n";
    }

    public function runSecurityTests() {
        $this->printHeader();

        // Test 1: Try to list objects without credentials
        $this->testListObjectsWithoutCredentials();

        // Test 2: Try to upload file without credentials
        $this->testUploadWithoutCredentials();

        // Test 3: Try to download file without credentials
        $this->testDownloadWithoutCredentials();

        // Test 4: Try to delete file without credentials
        $this->testDeleteWithoutCredentials();

        // Test 5: Try to access bucket with invalid credentials
        $this->testInvalidCredentials();

        $this->printFooter();
    }

    private function testListObjectsWithoutCredentials() {
        try {
            $s3Client = new S3Client([
                'version' => 'latest',
                'region'  => $this->region,
                'credentials' => false
            ]);

            $result = $s3Client->listObjects([
                'Bucket' => $this->bucket
            ]);

            $this->printTestResult(
                1,
                "List Objects Without Credentials",
                false,
                self::RED . "WARNING: Successfully listed objects without credentials!\n" .
                "This is a security vulnerability. The bucket should be private." . self::RESET
            );
        } catch (AwsException $e) {
            $this->printTestResult(
                1,
                "List Objects Without Credentials",
                true,
                "Access denied as expected. Error: " . $e->getMessage()
            );
        }
    }

    private function testUploadWithoutCredentials() {
        try {
            $s3Client = new S3Client([
                'version' => 'latest',
                'region'  => $this->region,
                'credentials' => false
            ]);

            $result = $s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key'    => 'security_test/unauthorized_upload.txt',
                'Body'   => 'This is an unauthorized upload test'
            ]);

            $this->printTestResult(
                2,
                "Upload Without Credentials",
                false,
                self::RED . "WARNING: Successfully uploaded file without credentials!\n" .
                "This is a security vulnerability. The bucket should not allow anonymous uploads." . self::RESET
            );
        } catch (AwsException $e) {
            $this->printTestResult(
                2,
                "Upload Without Credentials",
                true,
                "Upload denied as expected. Error: " . $e->getMessage()
            );
        }
    }

    private function testDownloadWithoutCredentials() {
        try {
            $s3Client = new S3Client([
                'version' => 'latest',
                'region'  => $this->region,
                'credentials' => false
            ]);

            $result = $s3Client->getObject([
                'Bucket' => $this->bucket,
                'Key'    => 'test_uploads/test.txt'
            ]);

            $this->printTestResult(
                3,
                "Download Without Credentials",
                false,
                self::RED . "WARNING: Successfully downloaded file without credentials!\n" .
                "This is a security vulnerability. The bucket should not allow anonymous downloads." . self::RESET
            );
        } catch (AwsException $e) {
            $this->printTestResult(
                3,
                "Download Without Credentials",
                true,
                "Download denied as expected. Error: " . $e->getMessage()
            );
        }
    }

    private function testDeleteWithoutCredentials() {
        try {
            $s3Client = new S3Client([
                'version' => 'latest',
                'region'  => $this->region,
                'credentials' => false
            ]);

            $result = $s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => 'test_uploads/test.txt'
            ]);

            $this->printTestResult(
                4,
                "Delete Without Credentials",
                false,
                self::RED . "WARNING: Successfully deleted file without credentials!\n" .
                "This is a security vulnerability. The bucket should not allow anonymous deletions." . self::RESET
            );
        } catch (AwsException $e) {
            $this->printTestResult(
                4,
                "Delete Without Credentials",
                true,
                "Delete denied as expected. Error: " . $e->getMessage()
            );
        }
    }

    private function testInvalidCredentials() {
        try {
            $s3Client = new S3Client([
                'version' => 'latest',
                'region'  => $this->region,
                'credentials' => [
                    'key'    => 'invalid_key',
                    'secret' => 'invalid_secret'
                ]
            ]);

            $result = $s3Client->listObjects([
                'Bucket' => $this->bucket
            ]);

            $this->printTestResult(
                5,
                "Invalid Credentials Test",
                false,
                self::RED . "WARNING: Successfully accessed bucket with invalid credentials!\n" .
                "This is a security vulnerability. The bucket should validate credentials properly." . self::RESET
            );
        } catch (AwsException $e) {
            $this->printTestResult(
                5,
                "Invalid Credentials Test",
                true,
                "Access denied as expected. Error: " . $e->getMessage()
            );
        }
    }
}

// Run the tests if executed directly
if (php_sapi_name() === 'cli') {
    $tester = new BucketSecurityTester();
    $tester->runSecurityTests();
} else {
    echo "This script should be run from the command line.\n";
}