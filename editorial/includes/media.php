<?php
declare(strict_types=1);

/**
 * Editorial V2 media upload service.
 *
 * Files are shared with the public site but paths stored in article payloads
 * always remain site-root relative: uploads/articles/YYYY/MM/file.ext.
 */

const EDITORIAL_MEDIA_MAX_BYTES = 8 * 1024 * 1024;
const EDITORIAL_MEDIA_MIME_EXTENSIONS = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];

final class EditorialMediaUploadException extends RuntimeException
{
    public int $httpStatus;

    public function __construct(string $message, int $httpStatus = 400)
    {
        $this->httpStatus = $httpStatus;
        parent::__construct($message);
    }
}

function editorial_media_site_root(): string
{
    return dirname(EDITORIAL_BASE_PATH);
}

function editorial_media_upload_root(): string
{
    return editorial_media_site_root() . '/uploads';
}

function editorial_media_article_directory(DateTimeImmutable $at): string
{
    return editorial_media_upload_root()
        . '/articles/' . $at->format('Y') . '/' . $at->format('m');
}

function editorial_media_public_path(string $filename, DateTimeImmutable $at): string
{
    return 'uploads/articles/'
        . rawurlencode($at->format('Y')) . '/'
        . rawurlencode($at->format('m')) . '/'
        . rawurlencode(basename($filename));
}

function editorial_media_safe_filename_base(string $originalName): string
{
    $basename = pathinfo(basename($originalName), PATHINFO_FILENAME);
    $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $basename) ?? '';
    $safe = trim($safe, '-_');
    if ($safe === '') {
        return 'image';
    }
    return substr($safe, 0, 100);
}

function editorial_media_unique_filename(string $directory, string $base, string $extension): string
{
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $suffix = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $candidate = $base . '-' . $suffix . '.' . $extension;
        if (!file_exists($directory . '/' . $candidate)) {
            return $candidate;
        }
    }
    throw new EditorialMediaUploadException('Không thể tạo tên file ảnh duy nhất.', 500);
}

/**
 * @return array{size:int,mime:string,extension:string}
 */
function editorial_media_inspect_image_file(string $path): array
{
    $actualSize = @filesize($path);
    if ($actualSize === false || $actualSize <= 0 || $actualSize > EDITORIAL_MEDIA_MAX_BYTES) {
        throw new EditorialMediaUploadException('Ảnh phải có dung lượng lớn hơn 0 và không vượt quá 8 MB.', 413);
    }
    if (!class_exists('finfo')) {
        throw new EditorialMediaUploadException('Máy chủ chưa hỗ trợ kiểm tra định dạng ảnh an toàn.', 500);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) @$finfo->file($path);
    $extension = EDITORIAL_MEDIA_MIME_EXTENSIONS[$mime] ?? '';
    if ($extension === '') {
        throw new EditorialMediaUploadException('Chỉ hỗ trợ ảnh JPG, PNG, GIF hoặc WEBP.', 415);
    }
    return ['size' => (int) $actualSize, 'mime' => $mime, 'extension' => $extension];
}

/**
 * @return array{directory:string,filename:string,destination:string,public_path:string}
 */
function editorial_media_prepare_image_destination(
    string $originalName,
    string $extension,
    DateTimeImmutable $stamp
): array
{
    $directory = editorial_media_article_directory($stamp);
    if (!is_dir($directory)
        && !@mkdir($directory, 0775, true)
        && !is_dir($directory)) {
        throw new EditorialMediaUploadException('Không thể chuẩn bị thư mục lưu ảnh.', 500);
    }
    $realDirectory = @realpath($directory);
    $realUploadRoot = @realpath(editorial_media_upload_root());
    if ($realDirectory === false
        || $realUploadRoot === false
        || !str_starts_with($realDirectory . DIRECTORY_SEPARATOR, $realUploadRoot . DIRECTORY_SEPARATOR)) {
        throw new EditorialMediaUploadException('Thư mục lưu ảnh không hợp lệ.', 500);
    }

    $filename = editorial_media_unique_filename(
        $realDirectory,
        editorial_media_safe_filename_base($originalName),
        $extension
    );
    $destination = $realDirectory . DIRECTORY_SEPARATOR . $filename;
    return [
        'directory' => $realDirectory,
        'filename' => $filename,
        'destination' => $destination,
        'public_path' => editorial_media_public_path($filename, $stamp),
    ];
}

/**
 * Persist an already validated local image without trusting its filename or
 * extension. The caller remains responsible for deleting the source temp file.
 *
 * @return array{name:string,size:int,mime:string,public_path:string,preview_url:string,absolute_path:string}
 */
function editorial_media_persist_local_image(
    string $sourcePath,
    string $suggestedName = 'image',
    ?DateTimeImmutable $stamp = null,
    bool $uploadedFile = false
): array
{
    if ($sourcePath === '' || !is_file($sourcePath)) {
        throw new EditorialMediaUploadException('File ảnh tạm không hợp lệ.');
    }
    if ($uploadedFile && !is_uploaded_file($sourcePath)) {
        throw new EditorialMediaUploadException('File upload không hợp lệ.');
    }
    $inspected = editorial_media_inspect_image_file($sourcePath);
    $stamp = $stamp ?? new DateTimeImmutable('now');
    $target = editorial_media_prepare_image_destination(
        $suggestedName,
        $inspected['extension'],
        $stamp
    );
    $destination = $target['destination'];

    $saved = false;
    $destinationCreated = false;
    if ($uploadedFile) {
        $saved = !file_exists($destination) && @move_uploaded_file($sourcePath, $destination);
        $destinationCreated = $saved;
    } else {
        $input = @fopen($sourcePath, 'rb');
        $output = !file_exists($destination) ? @fopen($destination, 'xb') : false;
        $destinationCreated = is_resource($output);
        if (is_resource($input) && is_resource($output)) {
            $copied = @stream_copy_to_stream($input, $output);
            $saved = $copied !== false && $copied === $inspected['size'];
        }
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_resource($output)) {
            fclose($output);
        }
    }
    if (!$saved) {
        if ($destinationCreated) {
            @unlink($destination);
        }
        throw new EditorialMediaUploadException(
            $uploadedFile ? 'Không thể lưu ảnh upload.' : 'Không thể lưu ảnh.',
            500
        );
    }
    $savedSize = @filesize($destination);
    if ($savedSize === false || $savedSize !== $inspected['size']) {
        @unlink($destination);
        throw new EditorialMediaUploadException(
            $uploadedFile ? 'Ảnh upload không được lưu đầy đủ.' : 'Ảnh không được lưu đầy đủ.',
            500
        );
    }

    return [
        'name' => $target['filename'],
        'size' => (int) $savedSize,
        'mime' => $inspected['mime'],
        'public_path' => $target['public_path'],
        'preview_url' => editorial_site_url($target['public_path']),
        'absolute_path' => $destination,
    ];
}

/**
 * @param array<string,mixed> $fileInput
 * @return array{name:string,size:int,mime:string,public_path:string,preview_url:string,absolute_path:string}
 */
function editorial_media_save_uploaded_image(array $fileInput): array
{
    $error = (int) ($fileInput['error'] ?? UPLOAD_ERR_NO_FILE);
    $tmpName = (string) ($fileInput['tmp_name'] ?? '');
    $originalName = (string) ($fileInput['name'] ?? 'image');
    $declaredSize = (int) ($fileInput['size'] ?? 0);

    if ($error !== UPLOAD_ERR_OK) {
        $status = in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400;
        throw new EditorialMediaUploadException('Upload ảnh thất bại. Vui lòng chọn lại file.', $status);
    }
    if ($declaredSize <= 0 || $declaredSize > EDITORIAL_MEDIA_MAX_BYTES) {
        throw new EditorialMediaUploadException('Ảnh phải có dung lượng lớn hơn 0 và không vượt quá 8 MB.', 413);
    }
    return editorial_media_persist_local_image($tmpName, $originalName, null, true);
}
