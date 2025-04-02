# S3 File Downloader

A powerful PHP utility script for interacting with AWS S3 buckets. This script provides a command-line interface for downloading files and directories from S3, with support for filtering, progress tracking, and error handling.

## Features

- Download single files or entire directories from S3
- Filter files by prefix and/or postfix
- Skip already downloaded files
- Progress tracking with percentage and file counts
- Support for large directories with automatic pagination
- File size display in MB
- Secure SSL handling
- Detailed error logging
- Delete files and directories from S3
- List directory contents with file sizes and dates

## Prerequisites

- PHP 7.4 or higher
- AWS SDK for PHP
- AWS credentials configured (via environment variables or AWS CLI)
- SSL support enabled in PHP

## Installation

1. Clone this repository or download the files
2. Install dependencies using Composer:
   ```bash
   composer install
   ```
3. Create a `.env` file with your AWS credentials:
   ```
   AWS_ACCESS_KEY_ID=your_access_key
   AWS_SECRET_ACCESS_KEY=your_secret_key
   AWS_REGION=your_region
   AWS_BUCKET=your_bucket_name
   ```

## Usage

### Basic Commands

```bash
# List directory contents
php download_s3.php list [prefix] [--max-keys=N]

# Download a single file
php download_s3.php download <s3_key> [local_path]

# Download a directory
php download_s3.php download-dir <s3_prefix> [local_path] [--prefix=file_prefix] [--postfix=file_postfix]

# Delete a single file
php download_s3.php delete <s3_key>

# Delete a directory
php download_s3.php delete-dir <s3_prefix>
```

### Examples

1. List files in a directory:
   ```bash
   php download_s3.php list videos/
   ```

2. Download a single file:
   ```bash
   php download_s3.php download videos/file.mp4 local_videos/file.mp4
   ```

3. Download all files from a directory:
   ```bash
   php download_s3.php download-dir videos/ local_videos
   ```

4. Download files with specific prefix:
   ```bash
   php download_s3.php download-dir videos/ local_videos --prefix=IMG_
   ```

5. Download files with specific postfix:
   ```bash
   php download_s3.php download-dir videos/ local_videos --postfix=_original.jpg
   ```

6. Download files with both prefix and postfix:
   ```bash
   php download_s3.php download-dir videos/ local_videos --prefix=IMG_ --postfix=_original.jpg
   ```

7. Delete a single file:
   ```bash
   php download_s3.php delete videos/file.mp4
   ```

8. Delete an entire directory:
   ```bash
   php download_s3.php delete-dir videos/
   ```

### Output Examples

1. Directory listing:
   ```
   Directories:
     videos/2024/
     videos/thumbnails/

   Files:
     videos/file1.mp4 (25.5 MB, 2024-03-15 14:30:00)
     videos/file2.mp4 (18.2 MB, 2024-03-15 14:35:00)
   ```

2. Download progress:
   ```
   Found 25 matching files to download...
   Progress: 45% (12/25 files) - SUCCESS: videos/IMG_2024_001_original.jpg

   Download Summary:
   Total matching files: 25
   Successfully downloaded: 23
   Skipped (already exists): 2
   Failed: 0
   ```

3. Delete summary:
   ```
   Delete Summary:
   Successfully deleted: 150 files
   Failed to delete: 0 files
   ```

## Features in Detail

### File Filtering
- `--prefix`: Filter files that start with the specified text
- `--postfix`: Filter files that end with the specified text
- Both filters can be used together

### Progress Tracking
- Shows percentage complete
- Displays current file being processed
- Shows number of files processed vs total
- Indicates file status (SUCCESS/SKIPPED/FAILED/ERROR)

### Error Handling
- Detailed error logging
- Graceful failure handling
- SSL certificate verification
- Automatic directory creation

### Performance
- Skips already downloaded files
- Handles large directories with pagination
- Processes files in batches of 1000
- Efficient memory usage

## Error Messages

Common error messages and their meanings:
- "Failed to generate presigned URL": AWS credentials or permissions issue
- "Failed to download file": Network or SSL issue
- "Failed to save file": Local filesystem permission issue
- "No matching files found": No files match the specified filters

## Security

- Uses AWS SDK's built-in security features
- SSL certificate verification enabled
- Secure credential handling via environment variables
- No hardcoded credentials

## Contributing

Feel free to submit issues and enhancement requests!

## License

This project is licensed under the MIT License - see the LICENSE file for details.