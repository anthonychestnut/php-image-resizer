<?php

// --- Configuration ---
// Directory for temporary downloaded and processed files, relative to this script
define('TEMP_DIR_IMAGE', __DIR__ . '/temp_image_files');
// Default output quality for JPEG and WebP (0-100, higher is better quality/larger file)
define('DEFAULT_JPEG_QUALITY', 85);
define('DEFAULT_WEBP_QUALITY', 85);
// Default output format if not specified
define('DEFAULT_OUTPUT_FORMAT', 'jpeg'); // jpeg, png, or webp
// Set execution and memory limits
define('MAX_EXECUTION_TIME_IMAGE', 120); // 2 minutes
define('MEMORY_LIMIT_IMAGE', '256M'); // Adjust based on typical image sizes
// --- End Configuration ---

// --- Global Variable for Log Path ---
$globalLogFilePathImage = null;

// --- Helper Functions ---

function writeToLogImage($message) {
    global $globalLogFilePathImage;
    $targetLogPath = $globalLogFilePathImage;
    if ($targetLogPath) {
        $timestamp = date('Y-m-d H:i:s');
        $logDir = dirname($targetLogPath);
        if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
        file_put_contents($targetLogPath, "[$timestamp] " . $message . "\n", FILE_APPEND | LOCK_EX);
    } else {
        error_log("ImageResizer Script (Log Path Not Set): " . $message);
    }
}

function handleErrorAndLogImage($errorMessage, $httpCode, $logContext = null) {
    global $globalLogFilePathImage;
    $logMessage = "Error (HTTP $httpCode): $errorMessage";
    if ($logContext) {
        $contextStr = is_string($logContext) ? $logContext : print_r($logContext, true);
        if (strlen($contextStr) > 1024) { $contextStr = substr($contextStr, 0, 1024) . "..."; }
        $logMessage .= "\nContext: " . $contextStr;
    }
    writeToLogImage($logMessage);
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
    http_response_code($httpCode);
    echo json_encode(["error" => $errorMessage]);
    exit;
}

function downloadImageFromUrl($url, &$tempFiles) {
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        throw new Exception("Invalid image_url provided.");
    }
    writeToLogImage("Downloading image from: $url");
    $pathInfo = pathinfo(parse_url($url, PHP_URL_PATH));
    $ext = strtolower($pathInfo['extension'] ?? 'tmp');
    $allowedDownloadExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    if (!in_array($ext, $allowedDownloadExt) && $ext !== 'tmp') {
        $headers = @get_headers($url, 1);
        if ($headers && isset($headers['Content-Type'])) {
            $contentType = is_array($headers['Content-Type']) ? strtolower(end($headers['Content-Type'])) : strtolower($headers['Content-Type']);
            if (strpos($contentType, 'image/jpeg') !== false) $ext = 'jpg';
            elseif (strpos($contentType, 'image/png') !== false) $ext = 'png';
            elseif (strpos($contentType, 'image/gif') !== false) $ext = 'gif';
            elseif (strpos($contentType, 'image/webp') !== false) $ext = 'webp';
            else writeToLogImage("Could not determine valid image type from URL Content-Type: " . $contentType);
        } else {
             writeToLogImage("Could not determine valid image type from URL extension or Content-Type.");
        }
    }

    $tempFilename = 'download_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $tempPath = TEMP_DIR_IMAGE . '/' . $tempFilename;

    $ch = curl_init($url);
    $fp = fopen($tempPath, 'wb');
    if (!$fp) throw new Exception("Failed to open temporary file for writing: $tempPath");

    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);
    curl_exec($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    fclose($fp);
    curl_close($ch);

    if ($curlError || $httpCode >= 400 || !file_exists($tempPath) || filesize($tempPath) == 0) {
        @unlink($tempPath);
        throw new Exception("Failed to download image. HTTP: $httpCode. Error: $curlError");
    }
    $tempFiles[] = $tempPath;
    return $tempPath;
}

function cleanupTempFilesImage(array $files) {
    $filesToClean = array_filter($files);
    if (empty($filesToClean)) return;
    writeToLogImage("Cleaning up temp files: " . implode(', ', $filesToClean));
    foreach ($filesToClean as $file) {
        if (file_exists($file)) { @unlink($file); }
    }
}

/**
 * Sanitizes a filename by removing potentially unsafe characters and directory traversal.
 */
function sanitizeFilename($filename) {
    // Remove .. and / to prevent directory traversal
    $filename = str_replace(['..', '/'], '', $filename);
    // Remove any characters that are not alphanumeric, hyphen, underscore, or period
    $filename = preg_replace('/[^A-Za-z0-9_.-]/', '', $filename);
    // Reduce multiple dots to a single dot
    $filename = preg_replace('/\.+/', '.', $filename);
    // Trim leading/trailing dots or hyphens
    $filename = trim($filename, '.-_');
    // If empty after sanitization, provide a default
    if (empty($filename)) {
        return 'processed_image';
    }
    return $filename;
}


function resizeAndOptimizeImage($sourcePath, $targetWidth, $targetHeight, $outputFormat, $quality, $outputFilenameBase, &$tempFiles) {
    list($sourceWidth, $sourceHeight, $sourceType) = @getimagesize($sourcePath);
    if (!$sourceWidth || !$sourceHeight) {
        throw new Exception("Could not read image dimensions or unsupported image type at: $sourcePath");
    }

    $image = null;
    switch ($sourceType) {
        case IMAGETYPE_JPEG: $image = @imagecreatefromjpeg($sourcePath); break;
        case IMAGETYPE_PNG:  $image = @imagecreatefrompng($sourcePath);  break;
        case IMAGETYPE_GIF:  $image = @imagecreatefromgif($sourcePath);  break;
        case IMAGETYPE_WEBP: if (function_exists('imagecreatefromwebp')) $image = @imagecreatefromwebp($sourcePath); break;
        case IMAGETYPE_BMP:  if (function_exists('imagecreatefrombmp'))  $image = @imagecreatefrombmp($sourcePath);  break;
    }

    if (!$image) {
        throw new Exception("Failed to load image. Unsupported source image format or error reading file. Type detected: " . image_type_to_mime_type($sourceType));
    }

    if ($targetWidth && $targetHeight) {
        $newWidth = $targetWidth;
        $newHeight = $targetHeight;
    } elseif ($targetWidth) {
        $newWidth = $targetWidth;
        $newHeight = floor($sourceHeight * ($targetWidth / $sourceWidth));
    } elseif ($targetHeight) {
        $newHeight = $targetHeight;
        $newWidth = floor($sourceWidth * ($targetHeight / $sourceHeight));
    } else {
        $newWidth = $sourceWidth;
        $newHeight = $sourceHeight;
    }
    $newWidth = max(1, intval($newWidth));
    $newHeight = max(1, intval($newHeight));

    $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
    if (!$resizedImage) throw new Exception("Failed to create true color image resource for resizing.");

    if (($outputFormat === 'png' || $outputFormat === 'webp') && ($sourceType === IMAGETYPE_PNG || $sourceType === IMAGETYPE_GIF || $sourceType === IMAGETYPE_WEBP)) {
        imagealphablending($resizedImage, false);
        imagesavealpha($resizedImage, true);
        $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
        imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $transparent);
        imagecolortransparent($resizedImage, $transparent);
    } elseif ($outputFormat === 'jpeg' && ($sourceType === IMAGETYPE_PNG || $sourceType === IMAGETYPE_GIF || $sourceType === IMAGETYPE_WEBP)){
        $white = imagecolorallocate($resizedImage, 255, 255, 255);
        imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $white);
    }

    if (!imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight)) {
        imagedestroy($image);
        imagedestroy($resizedImage);
        throw new Exception("Failed to resample image.");
    }

    // Use the sanitized outputFilenameBase for the filename
    $finalOutputFilename = $outputFilenameBase . '.' . $outputFormat;
    $outputPath = TEMP_DIR_IMAGE . '/' . $finalOutputFilename;


    $success = false;
    switch ($outputFormat) {
        case 'jpeg':
        case 'jpg':
            $success = @imagejpeg($resizedImage, $outputPath, $quality);
            break;
        case 'png':
            $pngCompression = 9 - round(($quality / 100) * 9);
            $success = @imagepng($resizedImage, $outputPath, $pngCompression);
            break;
        case 'webp':
            if (function_exists('imagewebp')) {
                $success = @imagewebp($resizedImage, $outputPath, $quality);
            } else {
                throw new Exception("WebP output is not supported by this PHP GD configuration.");
            }
            break;
        default:
            throw new Exception("Unsupported output format: $outputFormat");
    }

    imagedestroy($image);
    imagedestroy($resizedImage);

    if (!$success || !file_exists($outputPath) || filesize($outputPath) == 0) {
        throw new Exception("Failed to save processed image to: $outputPath");
    }

    $tempFiles[] = $outputPath;
    // Return the full path and the final filename separately for Content-Disposition
    return ['path' => $outputPath, 'filename' => $finalOutputFilename];
}

// --- Script Execution Starts ---
$scriptNameImage = basename($_SERVER['PHP_SELF']);
$scriptDirPathImage = dirname($_SERVER['SCRIPT_NAME']);
$scriptDirPathImage = ($scriptDirPathImage == '/' || $scriptDirPathImage == '\\') ? '' : $scriptDirPathImage;
$protocolImage = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http");
$hostImage = $_SERVER['HTTP_HOST'];
$serverUrlImage = $protocolImage . "://" . $hostImage . $scriptDirPathImage . "/{$scriptNameImage}";


// --- Handle GET Request (API Documentation) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/html; charset=utf-8');

    $gdInstalled = extension_loaded('gd') && function_exists('gd_info') ? 'Installed ✅ (' . gd_info()['GD Version'] . ')' : 'Not Installed ❌ (Required for image processing)';
    $curlInstalledImage = function_exists('curl_version') ? 'Installed ✅' : 'Not Installed ❌ (Required for URL downloads)';
    $tempDirWritableImage = false;
    $permissionsInfoImage = [];
    if (!is_dir(TEMP_DIR_IMAGE)) @mkdir(TEMP_DIR_IMAGE, 0775, true);
    if (is_dir(TEMP_DIR_IMAGE) && is_writable(TEMP_DIR_IMAGE)) $tempDirWritableImage = true;
    $permissionsInfoImage[] = 'Temp Dir (' . basename(TEMP_DIR_IMAGE) . '): ' . ($tempDirWritableImage ? 'Writable ✅' : 'Not Writable ❌');
    $prerequisitesOk = (strpos($gdInstalled, '✅') !== false) && (strpos($curlInstalledImage, '✅') !== false) && $tempDirWritableImage;

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Image Resizer/Optimizer API</title>
        <link href='https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap' rel='stylesheet'>
        <style>
            body { font-family: 'Roboto', sans-serif; background-color: #f4f4f5; margin: 0; padding: 0; color: #18181b; line-height: 1.6; }
            h1 { background-color: #6366f1; color: white; padding: 20px; text-align: center; margin: 0; font-weight: 500; }
            div.container { padding: 20px 30px 40px 30px; margin: 30px auto; max-width: 900px; background: white; border-radius: 0.5rem; box-shadow: 0 1px 3px 0 rgba(0,0,0,0.1), 0 1px 2px -1px rgba(0,0,0,0.1); }
            h2 { border-bottom: 2px solid #e4e4e7; padding-bottom: 10px; margin-top: 30px; color: #4f46e5; font-weight: 500;}
            code, pre { background-color: #f3f4f6; padding: 12px 15px; border: 1px solid #e5e7eb; border-radius: 5px; display: block; margin-bottom: 15px; white-space: pre-wrap; word-wrap: break-word; font-family: Consolas, Monaco, 'Andale Mono', 'Ubuntu Mono', monospace; font-size: 0.9em; color: #3f3f46; }
            ul { list-style-type: disc; margin-left: 20px; padding-left: 5px;}
            li { margin-bottom: 10px; }
            strong { color: #4338ca; font-weight: 500; }
            button, .button-like {
                padding: 10px 20px;
                background-color: #6366f1;
                color: white;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                margin-top: 10px;
                font-size: 0.95em;
                text-decoration: none;
                display: inline-block;
                text-align: center;
            }
            button:hover, .button-like:hover { background-color: #4f46e5; }
            .note { background-color: #fefce8; border-left: 4px solid #eab308; padding: 12px 15px; margin: 20px 0; border-radius: 4px;}
            .error { color: #ef4444; font-weight: bold; }
            .success { color: #22c55e; font-weight: bold; }
            .status-list li { margin-bottom: 5px; list-style-type: none;}
            .status-icon { margin-right: 8px; display: inline-block; width: 20px; text-align: center;}
            .attribution a { color: #6366f1; text-decoration: none; }
            .attribution a:hover { text-decoration: underline; }
        </style>
    </head>
    <body>
        <h1>Image Resizer/Optimizer API</h1>
        <div class="container">
            <p style="text-align:center;">
                <img src="https://blog.automation-tribe.com/wp-content/uploads/2025/05/logo-automation-tribe-750.webp" alt="Automation Tribe Logo" style="max-width: 200px; margin-bottom: 10px;">
            </p>
            <p class="attribution" style="text-align:center; font-size: 0.9em; margin-bottom: 25px;">
                This API endpoint was made by <a href="https://www.automation-tribe.com" target="_blank" rel="noopener noreferrer">Automation Tribe</a>.<br>
                Join our community at <a href="https://www.skool.com/automation-tribe" target="_blank" rel="noopener noreferrer">https://www.skool.com/automation-tribe</a>.
            </p>
            <p>This API resizes and optimizes images provided via URL or direct upload. Input is via <strong>form-data (multipart/form-data)</strong>.</p>
            <p class="note"><strong>Logging:</strong> On errors, a log file (e.g., <code>image_resize_timestamp.log</code>) will be created in the 'logs' subfolder of <code><?php echo htmlspecialchars(basename(TEMP_DIR_IMAGE)); ?></code>.</p>

            <h2>Server Status</h2>
            <ul class="status-list">
                <li><span class="<?php echo strpos($gdInstalled, '✅') !== false ? 'success' : 'error'; ?>"><span class="status-icon"><?php echo strpos($gdInstalled, '✅') !== false ? '✅' : '❌'; ?></span><strong>PHP GD Extension:</strong> <?php echo $gdInstalled; ?></span></li>
                <li><span class="<?php echo strpos($curlInstalledImage, '✅') !== false ? 'success' : 'error'; ?>"><span class="status-icon"><?php echo strpos($curlInstalledImage, '✅') !== false ? '✅' : '❌'; ?></span><strong>PHP cURL Extension:</strong> <?php echo $curlInstalledImage; ?></span></li>
                <?php foreach ($permissionsInfoImage as $perm): ?>
                    <li><span class="<?php echo strpos($perm, '✅') !== false ? 'success' : 'error'; ?>"><span class="status-icon"><?php echo strpos($perm, '✅') !== false ? '✅' : '❌'; ?></span><?php echo $perm; ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php if (!$prerequisitesOk): ?>
                <p class="error note"><strong>Action Required:</strong> Please address server status items marked with ❌. Ensure PHP GD and cURL extensions are enabled, and the temp directory is writable. Contact your host or server administrator if you need assistance with PHP extensions.</p>
            <?php endif; ?>

            <h2>API Usage</h2>
            <h3>Endpoint</h3>
            <code><?php echo htmlspecialchars($serverUrlImage); ?></code>

            <h3>HTTP Method</h3>
            <code>POST</code>

            <h3>Content Type</h3>
            <code>multipart/form-data</code>

            <h3>Form Data Parameters:</h3>
            <ul>
                <li><code>image_file</code> (Optional if <code>image_url</code> provided): The image file to upload.</li>
                <li><code>image_url</code> (Optional if <code>image_file</code> provided): URL of the image to process.</li>
                <li><code>width</code> (Optional): Desired width in pixels. Aspect ratio maintained if only width is set.</li>
                <li><code>height</code> (Optional): Desired height in pixels. Aspect ratio maintained if only height is set. (If both width and height are set, image is resized to those exact dimensions, potentially altering aspect ratio).</li>
                <li><code>quality</code> (Optional): Output quality (0-100). Default: <?php echo DEFAULT_JPEG_QUALITY; ?> for JPEG/WebP. (For PNG, this controls compression level).</li>
                <li><code>format</code> (Optional): Desired output format (<code>jpeg</code>, <code>png</code>, <code>webp</code>). Default: <code><?php echo DEFAULT_OUTPUT_FORMAT; ?></code>.</li>
                <li><code>output_filename</code> (Optional): Desired base name for the output file (e.g., "my_custom_image"). Extension will be added based on 'format'. Default: "processed_image_timestamp_random".</li>
            </ul>
            <p class="note">You must provide either <code>image_file</code> or <code>image_url</code>.</p>

            <h3>Success Response</h3>
            <p>On success, the API returns the processed image directly with the appropriate <code>Content-Type</code> header (e.g., <code>image/jpeg</code>) and a <code>Content-Disposition: inline; filename="your_filename.jpg"</code> header. You can display this image directly or save it.</p>

            <h3>Error Response (JSON)</h3>
            <pre><code><?php echo htmlspecialchars(json_encode(["error" => "Descriptive error message."], JSON_PRETTY_PRINT)); ?></code></pre>

            <h2>How to Use (cURL Example)</h2>
            <p><strong>Using image_url with custom filename:</strong></p>
            <pre id="curl-command-url"><?php
                $curlCommandUrl = "curl -X POST " . escapeshellarg($serverUrlImage) . " \\\n";
                $curlCommandUrl .= "  -F \"image_url=https://www.php.net/images/logos/new-php-logo.png\" \\\n";
                $curlCommandUrl .= "  -F \"width=300\" \\\n";
                $curlCommandUrl .= "  -F \"format=jpeg\" \\\n";
                $curlCommandUrl .= "  -F \"quality=80\" \\\n";
                $curlCommandUrl .= "  -F \"output_filename=php_logo_resized\" \\\n";
                $curlCommandUrl .= "  -o php_logo_resized.jpg";
                echo htmlspecialchars($curlCommandUrl);
            ?></pre>
            <button onclick="copyCurlToClipboard('curl-command-url')">Copy cURL (image_url)</button>

            <p style="margin-top: 20px;"><strong>Uploading a local file (replace <code>path/to/your/image.png</code>):</strong></p>
            <pre id="curl-command-file"><?php
                $curlCommandFile = "curl -X POST " . escapeshellarg($serverUrlImage) . " \\\n";
                $curlCommandFile .= "  -F \"image_file=@path/to/your/image.png\" \\\n";
                $curlCommandFile .= "  -F \"height=200\" \\\n";
                $curlCommandFile .= "  -F \"format=webp\" \\\n";
                $curlCommandFile .= "  -F \"output_filename=my_uploaded_image\" \\\n";
                $curlCommandFile .= "  -o my_uploaded_image.webp";
                echo htmlspecialchars($curlCommandFile);
            ?></pre>
            <button onclick="copyCurlToClipboard('curl-command-file')">Copy cURL (image_file)</button>

            <h2>Important Notes</h2>
            <ul>
                <li><strong>PHP GD Extension:</strong> This is the core of the image processing. Ensure it's enabled and supports the input/output formats you need.</li>
                <li><strong>Memory & Execution Time:</strong> Processing large images can be resource-intensive. Adjust script constants and server PHP limits if needed.</li>
                <li><strong>WebP Support:</strong> Depends on your GD library's compilation.</li>
                <li><strong>Filename Sanitization:</strong> Custom output filenames are sanitized to prevent security issues.</li>
            </ul>
            <button onclick="location.reload()" class="button-like" style="margin-top: 20px;">Refresh Status & Documentation</button>
        </div>

        <script>
            function copyCurlToClipboard(elementId) {
                const preElement = document.getElementById(elementId);
                if (preElement) {
                    const textToCopy = preElement.innerText.replace(/\\\n\s*/g, ' ');
                    navigator.clipboard.writeText(textToCopy).then(function() {
                        alert('cURL command copied to clipboard (single line format)!');
                    }, function(err) {
                        alert('Failed to copy cURL command. See console for error.');
                        console.error('Could not copy text: ', err);
                    });
                }
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

// --- Handle POST Request (Process Image) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(MAX_EXECUTION_TIME_IMAGE);
    ini_set('memory_limit', MEMORY_LIMIT_IMAGE);

    $logDirForImage = TEMP_DIR_IMAGE . '/logs';
    if (!is_dir($logDirForImage)) { @mkdir($logDirForImage, 0775, true); }
    $baseLogNameImage = 'image_resize_' . time();
    $globalLogFilePathImage = $logDirForImage . '/' . $baseLogNameImage . '.log';

    if (!extension_loaded('gd') || !function_exists('gd_info')) {
        handleErrorAndLogImage("PHP GD extension is not installed or enabled. It's required for image processing.", 500);
    }
    if (!is_dir(TEMP_DIR_IMAGE) || !is_writable(TEMP_DIR_IMAGE)) {
        handleErrorAndLogImage("Temporary directory '" . TEMP_DIR_IMAGE . "' is not writable or does not exist.", 500);
    }

    $imageUrl = $_POST['image_url'] ?? null;
    $imageFile = $_FILES['image_file'] ?? null;

    $targetWidth = isset($_POST['width']) ? intval($_POST['width']) : null;
    $targetHeight = isset($_POST['height']) ? intval($_POST['height']) : null;
    $quality = isset($_POST['quality']) ? intval($_POST['quality']) : null;
    $outputFormat = isset($_POST['format']) ? strtolower(trim($_POST['format'])) : DEFAULT_OUTPUT_FORMAT;
    $outputFilenameParam = $_POST['output_filename'] ?? null; // New parameter

    $tempFiles = [];
    $sourceImagePath = null;

    try {
        if ($imageFile && $imageFile['error'] === UPLOAD_ERR_OK) {
            $uploadName = basename($imageFile['name']);
            $safeUploadName = preg_replace('/[^A-Za-z0-9._-]/', '', $uploadName);
            $sourceImagePath = TEMP_DIR_IMAGE . '/' . 'upload_' . time() . '_' . $safeUploadName;
            if (!move_uploaded_file($imageFile['tmp_name'], $sourceImagePath)) {
                throw new Exception("Failed to move uploaded file. Check permissions for " . TEMP_DIR_IMAGE);
            }
            $tempFiles[] = $sourceImagePath;
            writeToLogImage("Image uploaded to: $sourceImagePath");
        } elseif ($imageUrl) {
            $sourceImagePath = downloadImageFromUrl($imageUrl, $tempFiles);
             writeToLogImage("Image downloaded from URL to: $sourceImagePath");
        } else {
            throw new Exception("No image_file uploaded or image_url provided.");
        }

        if (!$sourceImagePath || !file_exists($sourceImagePath)) {
             throw new Exception("Source image path is invalid or file does not exist.");
        }

        if ($targetWidth !== null && ($targetWidth <= 0 || $targetWidth > 10000)) $targetWidth = null;
        if ($targetHeight !== null && ($targetHeight <= 0 || $targetHeight > 10000)) $targetHeight = null;

        if ($quality === null) {
            $quality = ($outputFormat === 'webp') ? DEFAULT_WEBP_QUALITY : DEFAULT_JPEG_QUALITY;
        } else {
            $quality = max(0, min(100, $quality));
        }

        $allowedFormats = ['jpeg', 'jpg', 'png', 'webp'];
        if (!in_array($outputFormat, $allowedFormats)) {
            $outputFormat = DEFAULT_OUTPUT_FORMAT;
        }
        if ($outputFormat === 'jpg') $outputFormat = 'jpeg';

        // Determine output filename base
        $outputFilenameBase = 'processed_image_' . time() . '_' . bin2hex(random_bytes(2)); // Default
        if ($outputFilenameParam) {
            $sanitizedName = sanitizeFilename($outputFilenameParam);
            if (!empty($sanitizedName)) {
                $outputFilenameBase = $sanitizedName;
            }
        }

        writeToLogImage("Processing image: $sourceImagePath. Target Filename Base: $outputFilenameBase, Width: $targetWidth, Height: $targetHeight, Format: $outputFormat, Quality: $quality");

        $processedResult = resizeAndOptimizeImage($sourceImagePath, $targetWidth, $targetHeight, $outputFormat, $quality, $outputFilenameBase, $tempFiles);
        $processedImagePath = $processedResult['path'];
        $finalOutputFilenameForHeader = $processedResult['filename'];


        writeToLogImage("Image processed successfully: $processedImagePath. Final filename for header: $finalOutputFilenameForHeader");

        $mimeType = 'image/jpeg';
        if ($outputFormat === 'png') $mimeType = 'image/png';
        if ($outputFormat === 'webp') $mimeType = 'image/webp';

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($processedImagePath));
        header('Content-Disposition: inline; filename="' . $finalOutputFilenameForHeader . '"');
        readfile($processedImagePath);

    } catch (Exception $e) {
        handleErrorAndLogImage($e->getMessage(), 400, $_POST);
    } finally {
        cleanupTempFilesImage($tempFiles);
    }
    exit;
} else {
    http_response_code(405);
    echo json_encode(["error" => "Method Not Allowed"]);
}
?>