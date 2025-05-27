<?php
session_start();

// --- Configuration ---
define('METADATA_FILE', 'image_metadata.json');
define('UPLOADS_DIR', 'uploads');
define('THUMBNAILS_DIR', 'thumbnails');
define('THUMBNAIL_WIDTH', 750);
define('THUMBNAIL_HEIGHT', 750);
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10 MB
define('ALLOWED_MIME_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('FILE_RETENTION_DAYS', 3);

// --- Helper Functions ---
function generate_unique_id() {
  return bin2hex(random_bytes(10));
}

function ensure_directory_exists($dir) {
  if (!file_exists($dir)) {
    mkdir($dir, 0755, true);
  }
}

function read_metadata() {
  if (!file_exists(METADATA_FILE)) {
    return [];
  }
  $json_data = @file_get_contents(METADATA_FILE);
  if ($json_data === false) return [];
  $data = json_decode($json_data, true);
  return (is_array($data)) ? $data : [];
}

function save_all_metadata(array $all_data) {
  return file_put_contents(METADATA_FILE, json_encode($all_data, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function get_metadata_by_id($id) {
  $all_metadata = read_metadata();
  foreach ($all_metadata as $data) {
    if (isset($data['id']) && $data['id'] === $id) {
      return $data;
    }
  }
  return null;
}

function create_thumbnail($source_tmp_path, $target_path, $original_mime_type, $target_width, $target_height) {
  list($width, $height, $type_const) = @getimagesize($source_tmp_path);
  if (!$width || !$height) return false;

  $source_image = null;
  switch ($type_const) {
    case IMAGETYPE_JPEG: $source_image = @imagecreatefromjpeg($source_tmp_path); break;
    case IMAGETYPE_PNG: $source_image = @imagecreatefrompng($source_tmp_path); break;
    case IMAGETYPE_GIF: $source_image = @imagecreatefromgif($source_tmp_path); break;
    case IMAGETYPE_WEBP: $source_image = @imagecreatefromwebp($source_tmp_path); break;
    default: return false;
  }
  if (!$source_image) return false;

  $aspect_ratio = $width / $height;
  if ($target_width / $target_height > $aspect_ratio) {
    $new_height = (int)$target_height;
    $new_width = (int)($target_height * $aspect_ratio);
  } else {
    $new_width = (int)$target_width;
    $new_height = (int)($target_width / $aspect_ratio);
  }

  $thumb_image = imagecreatetruecolor($new_width, $new_height);
  
  $output_thumb_mime = 'image/webp';
  if ($original_mime_type === 'image/gif' && function_exists('imagegif')) {
    $output_thumb_mime = 'image/gif';
  } elseif (!function_exists('imagewebp')) {
    $output_thumb_mime = 'image/png';
  }

  if ($output_thumb_mime === 'image/png' || $output_thumb_mime === 'image/gif' || $output_thumb_mime === 'image/webp') {
    imagealphablending($thumb_image, false);
    imagesavealpha($thumb_image, true);
    $transparent_color = imagecolorallocatealpha($thumb_image, 0, 0, 0, 127);
    imagefill($thumb_image, 0, 0, $transparent_color);
  } else {
    $white = imagecolorallocate($thumb_image, 255, 255, 255);
    imagefilledrectangle($thumb_image, 0, 0, $new_width, $new_height, $white);
  }

  imagecopyresampled($thumb_image, $source_image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

  $success = false;
  switch ($output_thumb_mime) {
    case 'image/jpeg': $success = imagejpeg($thumb_image, $target_path, 85); break;
    case 'image/png':  $success = imagepng($thumb_image, $target_path, 7); break;
    case 'image/gif':  $success = imagegif($thumb_image, $target_path); break;
    case 'image/webp': $success = imagewebp($thumb_image, $target_path, 80); break;
  }

  imagedestroy($source_image);
  imagedestroy($thumb_image);

  return $success ? $output_thumb_mime : false;
}

function cleanup_old_files() {
  $all_metadata = read_metadata();
  $now = time();
  $cutoff = strtotime('-' . FILE_RETENTION_DAYS . ' days');

  foreach ($all_metadata as $key => $data) {
    if ($data['timestamp'] < $cutoff) {
      // Delete original file
      if (isset($data['original_path'])) {
        @unlink($data['original_path']);
      }
      // Delete thumbnail file
      if (isset($data['thumbnail_path'])) {
        @unlink($data['thumbnail_path']);
      }
      // Remove from metadata
      unset($all_metadata[$key]);
    }
  }

  save_all_metadata($all_metadata);
}

// --- Initial Checks ---
$config_error = null;
$metadata_file_path = METADATA_FILE;
$metadata_dir = dirname($metadata_file_path);
if ($metadata_dir === '.') $metadata_dir = '';

// Ensure uploads and thumbnails directories exist
ensure_directory_exists(UPLOADS_DIR);
ensure_directory_exists(THUMBNAILS_DIR);

if ( (!file_exists($metadata_file_path) && !is_writable($metadata_dir === '' ? '.' : $metadata_dir) ) || 
     (file_exists($metadata_file_path) && !is_writable($metadata_file_path)) ) {
  $config_error = "Error: The metadata file ('" . htmlspecialchars($metadata_file_path) . "') location is not writable. Please check permissions for the script's directory or the file itself.";
}

// Run cleanup on every request
cleanup_old_files();

// --- Main Logic ---
$action = $_GET['action'] ?? 'upload_form';
$image_id_view = $_GET['id'] ?? null;
$message = $_SESSION['message'] ?? null;
$message_type = $_SESSION['message_type'] ?? 'info';
unset($_SESSION['message'], $_SESSION['message_type']);
$base_url = strtok($_SERVER["REQUEST_URI"],'?');

if (!$config_error) {
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image_file'])) {
    $file = $_FILES['image_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
      $_SESSION['message'] = "File upload error code: " . $file['error']; $_SESSION['message_type'] = 'danger';
    } elseif ($file['size'] == 0) {
      $_SESSION['message'] = "No file selected or file is empty."; $_SESSION['message_type'] = 'warning';
    } elseif ($file['size'] > MAX_FILE_SIZE) {
      $_SESSION['message'] = "File is too large. Max size: " . (MAX_FILE_SIZE / 1024 / 1024) . " MB."; $_SESSION['message_type'] = 'danger';
    } else {
      $image_info = @getimagesize($file['tmp_name']);
      $original_mime_type = $image_info ? $image_info['mime'] : null;
      if (!$original_mime_type) { 
        $finfo = finfo_open(FILEINFO_MIME_TYPE); 
        $original_mime_type = finfo_file($finfo, $file['tmp_name']); 
        finfo_close($finfo); 
      }
      $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

      if (!in_array($original_mime_type, ALLOWED_MIME_TYPES) || !in_array($extension, ALLOWED_EXTENSIONS)) {
        $_SESSION['message'] = "Invalid file type. Allowed: JPG, PNG, GIF, WEBP. Detected: " . htmlspecialchars($original_mime_type); $_SESSION['message_type'] = 'danger';
      } else {
        $image_id = generate_unique_id();
        $original_filename = basename($file['name']);
        
        // Save original file
        $original_path = UPLOADS_DIR . '/' . $image_id . '.' . $extension;
        if (!move_uploaded_file($file['tmp_name'], $original_path)) {
          $_SESSION['message'] = "Failed to save uploaded file."; $_SESSION['message_type'] = 'danger';
        } else {
          // Create and save thumbnail
          $thumbnail_path = THUMBNAILS_DIR . '/' . $image_id . '.webp';
          $thumbnail_mime = create_thumbnail($original_path, $thumbnail_path, $original_mime_type, THUMBNAIL_WIDTH, THUMBNAIL_HEIGHT);

          if ($thumbnail_mime) {
            $new_metadata_entry = [
              'id' => $image_id, 
              'original_name' => $original_filename,
              'original_path' => $original_path,
              'original_mime_type' => $original_mime_type,
              'thumbnail_path' => $thumbnail_path,
              'thumbnail_mime_type' => $thumbnail_mime,
              'size_kb' => round($file['size'] / 1024, 2),
              'dimensions' => ($image_info ? $image_info[0].'x'.$image_info[1] : 'N/A'),
              'timestamp' => time()
            ];
            
            $all_metadata = read_metadata();
            $all_metadata[] = $new_metadata_entry;

            if (save_all_metadata($all_metadata)) {
              $share_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . $base_url . "?action=view&id={$image_id}";
              $_SESSION['message'] = "Image uploaded! Share link: <a href='{$share_url}' class='alert-link'>{$share_url}</a>";
              $_SESSION['message_type'] = 'success';
            } else {
              // Clean up files if metadata save failed
              @unlink($original_path);
              @unlink($thumbnail_path);
              $_SESSION['message'] = "Image processed, but failed to save metadata."; $_SESSION['message_type'] = 'danger';
            }
          } else {
            @unlink($original_path);
            $_SESSION['message'] = "Image uploaded, but thumbnail creation failed."; $_SESSION['message_type'] = 'warning';
          }
        }
      }
    }
    header("Location: " . $base_url); exit;
  }

  if ($action === 'download' && $image_id_view) {
    $metadata = get_metadata_by_id($image_id_view);
    if ($metadata && isset($metadata['original_path']) && isset($metadata['original_mime_type'])) {
      if (file_exists($metadata['original_path'])) {
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $metadata['original_mime_type']);
        header('Content-Disposition: attachment; filename="' . htmlspecialchars($metadata['original_name']) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($metadata['original_path']));
        readfile($metadata['original_path']);
        exit;
      } else {
        $_SESSION['message'] = "Original file not found."; $_SESSION['message_type'] = 'danger';
      }
    } else {
      $_SESSION['message'] = "Image data not found for download."; $_SESSION['message_type'] = 'danger';
    }
    header("Location: " . $base_url); exit;
  }
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
  <head>
  
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AnonPhotoShare</title>
    <link rel="icon" type="image/svg" href="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxNiIgaGVpZ2h0PSIxNiIgZmlsbD0iY3VycmVudENvbG9yIiBjbGFzcz0iYmkgYmktY2FyZC1pbWFnZSIgdmlld0JveD0iMCAwIDE2IDE2Ij4KICA8cGF0aCBkPSJNNi4wMDIgNS41YTEuNSAxLjUgMCAxIDEtMyAwIDEuNSAxLjUgMCAwIDEgMyAwIi8+CiAgPHBhdGggZD0iTTEuNSAyQTEuNSAxLjUgMCAwIDAgMCAzLjV2OUExLjUgMS41IDAgMCAwIDEuNSAxNGgxM2ExLjUgMS41IDAgMCAwIDEuNS0xLjV2LTlBMS41IDEuNSAwIDAgMCAxNC41IDJ6bTEzIDFhLjUuNSAwIDAgMSAuNS41djZsLTMuNzc1LTEuOTQ3YS41LjUgMCAwIDAtLjU3Ny4wOTNsLTMuNzEgMy43MS0yLjY2LTEuNzcyYS41LjUgMCAwIDAtLjYzLjA2MkwxLjAwMiAxMnYuNTRMMSAxMi41di05YS41LjUgMCAwIDEgLjUtLjV6Ii8+Cjwvc3ZnPg==">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
      body { padding-top: 2rem; padding-bottom: 2rem; }
      .preview-container img { width: 100%; border: 1px solid var(--bs-border-color); }
      .toast-container { position: fixed; bottom: 1rem; right: 1rem; z-index: 1055; }
      .view-image-container img {
        object-fit: contain;
        width: 100%;
        max-height: 80vh; 
        background-color: var(--bs-tertiary-bg);
      }
    </style>
  </head>
  <body>
  
    <div class="container">
      <div class="text-center mb-4">
        <a class="text-decoration-none text-light" href="<?php echo htmlspecialchars($base_url); ?>">
          <h1 class="display-5 fw-bold"><i class="bi bi-image-fill"></i> AnonPhotoShare</h1>
        </a>
      </div>

      <?php if ($config_error): ?>
        <div class="alert alert-danger mt-3" role="alert"><strong>Configuration Error:</strong> <?php echo htmlspecialchars($config_error); ?></div>
      <?php else: ?>
        <?php if ($message): ?>
          <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show shadow-sm rounded-3" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <?php if ($action === 'view' && $image_id_view): ?>
          <?php $image_data = get_metadata_by_id($image_id_view); ?>
          <?php if ($image_data && isset($image_data['thumbnail_path'], $image_data['thumbnail_mime_type'], $image_data['original_path'], $image_data['original_mime_type'])): ?>
            <?php if (file_exists($image_data['original_path']) && file_exists($image_data['thumbnail_path'])): ?>
              <div class="card shadow-lg rounded-4 border-0 bg-body-tertiary">
                <div class="row g-0">
                  <div class="col-lg-7 d-flex align-items-center justify-content-center p-lg-4 p-3 view-image-container">
                    <a href="<?php echo htmlspecialchars($image_data['original_path']); ?>"
                       target="_blank"
                       title="View Full Image: <?php echo htmlspecialchars($image_data['original_name']); ?>">
                      <img src="<?php echo htmlspecialchars($image_data['thumbnail_path']); ?>"
                           alt="Thumbnail: <?php echo htmlspecialchars($image_data['original_name']); ?>"
                           class="w-100 rounded-3 border">
                    </a>
                  </div>
                  <div class="col-lg-5">
                    <div class="card-body p-4 p-md-5">
                      <h2 class="card-title h4 mb-1"><?php echo htmlspecialchars($image_data['original_name']); ?></h2>
                      <p class="text-body-secondary small mb-3">ID: <?php echo htmlspecialchars($image_data['id']); ?></p>

                      <dl class="row small">
                        <dt class="col-sm-5">Filename:</dt><dd class="col-sm-7"><?php echo htmlspecialchars($image_data['original_name']); ?></dd>
                        <dt class="col-sm-5">Type:</dt><dd class="col-sm-7"><?php echo htmlspecialchars($image_data['original_mime_type']); ?></dd>
                        <dt class="col-sm-5">Size:</dt><dd class="col-sm-7"><?php echo htmlspecialchars($image_data['size_kb']); ?> KB</dd>
                        <dt class="col-sm-5">Dimensions:</dt><dd class="col-sm-7"><?php echo htmlspecialchars($image_data['dimensions']); ?></dd>
                        <dt class="col-sm-5">Uploaded:</dt><dd class="col-sm-7"><?php echo htmlspecialchars(date('Y/m/d', $image_data['timestamp'])); ?></dd>
                      </dl>

                      <div class="mt-4 gap-2 btn-group w-100">
                        <a href="?action=download&id=<?php echo htmlspecialchars($image_data['id']); ?>" class="btn w-50 rounded btn-light fw-medium">Download</a>
                        <button id="shareButton" class="btn w-50 rounded btn-light fw-medium"
                                data-url="<?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . htmlspecialchars($_SERVER['REQUEST_URI']); ?>"
                                data-title="<?php echo htmlspecialchars($image_data['original_name']); ?>">Share
                        </button>
                      </div>
                      <hr class="my-4">
                      <a href="<?php echo htmlspecialchars($base_url); ?>" class="btn btn-light fw-medium w-100"><i class="bi bi-upload"></i> Upload Another</a>
                    </div>
                  </div>
                </div>
              </div>
            <?php else: ?>
              <div class="alert alert-warning shadow-sm rounded-3">Image files not found on server.</div>
              <a href="<?php echo htmlspecialchars($base_url); ?>" class="btn btn-secondary"><i class="bi bi-arrow-left-circle"></i> Back to Upload</a>
            <?php endif; ?>
          <?php else: ?>
            <div class="alert alert-warning shadow-sm rounded-3">Image not found, ID is invalid, or image data is incomplete.</div>
            <a href="<?php echo htmlspecialchars($base_url); ?>" class="btn btn-secondary"><i class="bi bi-arrow-left-circle"></i> Back to Upload</a>
          <?php endif; ?>

        <?php elseif ($action === 'thumbnail' && $image_id_view): ?>
          <?php $metadata = get_metadata_by_id($image_id_view); ?>
          <?php if ($metadata && isset($metadata['thumbnail_path']) && isset($metadata['thumbnail_mime_type'])): ?>
            <?php if (file_exists($metadata['thumbnail_path'])): ?>
              <?php
                header('Content-Type: ' . $metadata['thumbnail_mime_type']);
                header('Content-Length: ' . filesize($metadata['thumbnail_path']));
                readfile($metadata['thumbnail_path']);
                exit;
              ?>
            <?php endif; ?>
          <?php endif; ?>
          <?php header("HTTP/1.0 404 Not Found"); exit; ?>

        <?php else: /* Upload Form */ ?>
          <div class="row justify-content-center">
            <div class="col-lg-8 col-xl-7">
              <div class="card shadow-lg rounded-4 border-secondary p-4 p-md-5">
                <div class="card-body">
                  <div class="text-center mb-4">
                    <i class="bi bi-cloud-arrow-up-fill text-primary" style="font-size: 3rem;"></i>
                    <h2 class="h3 mt-2">Upload Your Image</h2>
                    <p class="text-body-secondary">Share anonymously. Max size: <?php echo (MAX_FILE_SIZE / 1024 / 1024); ?>MB. <br>Allowed: JPG, PNG, GIF, WEBP.</p>
                  </div>
                  <form action="<?php echo htmlspecialchars($base_url); ?>" method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                      <label for="image_file" class="form-label visually-hidden">Choose Image:</label>
                      <input type="file" class="form-control form-control-lg" id="image_file" name="image_file" 
                             accept=".jpg,.jpeg,.png,.gif,.webp" required onchange="previewImage(event)">
                    </div>
                    <div class="mb-3 text-center preview-container" id="imagePreviewContainer" style="min-height: 100px; display: none;">
                      <img id="imagePreview" src="#" alt="Image Preview" class="img-fluid rounded"/>
                    </div>
                    <div class="d-grid">
                      <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-upload"></i> Upload & Share</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="toast-container p-3">
      <div id="clipboardToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="toast-header">
          <strong class="me-auto">Notification</strong>
          <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="toast-body">
          Link copied to clipboard!
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    <script>
      function previewImage(event) {
        const reader = new FileReader();
        const previewContainer = document.getElementById('imagePreviewContainer');
        const preview = document.getElementById('imagePreview');
        reader.onload = function() {
          if (reader.readyState === 2) {
            preview.src = reader.result; previewContainer.style.display = 'block';
          }
        }
        if (event.target.files[0]) reader.readAsDataURL(event.target.files[0]);
        else { preview.src = '#'; previewContainer.style.display = 'none'; }
      }

      document.addEventListener('DOMContentLoaded', () => {
        const shareButton = document.getElementById('shareButton');
        const clipboardToastEl = document.getElementById('clipboardToast');
        const clipboardToast = clipboardToastEl ? new bootstrap.Toast(clipboardToastEl) : null;

        if (shareButton) {
          shareButton.addEventListener('click', async () => {
            const shareUrl = shareButton.dataset.url;
            const shareTitle = shareButton.dataset.title || document.title;

            if (navigator.share) {
              try {
                await navigator.share({ title: shareTitle, text: `Check out: ${shareTitle}`, url: shareUrl });
              } catch (err) {
                console.error('Error sharing:', err);
                if (err.name !== 'AbortError') { 
                  copyToClipboard(shareUrl, 'Sharing failed. Link copied!', clipboardToast);
                }
              }
            } else {
              copyToClipboard(shareUrl, 'Web Share not supported. Link copied!', clipboardToast);
            }
          });
        }
      });

      async function copyToClipboard(text, message, toastInstance) {
        try {
          await navigator.clipboard.writeText(text);
          if (toastInstance) {
            const toastBody = toastInstance._element.querySelector('.toast-body');
            if(toastBody) toastBody.textContent = message;
            toastInstance.show();
          } else {
            alert(message); 
          }
        } catch (err) {
          console.error('Failed to copy: ', err);
          if (toastInstance) {
            const toastBody = toastInstance._element.querySelector('.toast-body');
            if(toastBody) toastBody.textContent = 'Failed to copy link.';
            toastInstance.show();
          } else {
            alert('Failed to copy link.');
          }
        }
      }
      
      const form = document.querySelector('form');
      if(form) {
        form.addEventListener('reset', () => {
          const preview = document.getElementById('imagePreview');
          const previewContainer = document.getElementById('imagePreviewContainer');
          if (preview) preview.src = '#';
          if (previewContainer) previewContainer.style.display = 'none';
        });
      }
    </script>
  </body>
</html>