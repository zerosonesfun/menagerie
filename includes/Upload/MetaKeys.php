<?php
/**
 * Post meta keys shared by upload / companion handlers.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie\Upload;

if (! defined('ABSPATH')) {
	exit;
}

final class MetaKeys {
	/** Set by Tools → Image Converter after a successful conversion. */
	public const PROCESSED = '_menagerie_processed';

	public const ORIGINAL_BYTES = '_menagerie_original_bytes';

	public const OPTIMIZED_BYTES = '_menagerie_optimized_bytes';

	public const MIME_OUT = '_menagerie_mime_out';

	public const PROCESSED_AT = '_menagerie_processed_at';

	/** Relative path under uploads for the full-size JPEG companion. */
	public const JPEG_RELATIVE = '_menagerie_jpeg_relative';

	public const JPEG_WIDTH = '_menagerie_jpeg_width';

	public const JPEG_HEIGHT = '_menagerie_jpeg_height';

	/**
	 * JSON map of registered size name => relative uploads path for sized JPEG companions.
	 * Example: {"medium":"2026/01/photo-menagerie-fb-medium.jpg","large":"..."}
	 */
	public const JPEG_SIZES = '_menagerie_jpeg_sizes';

	/**
	 * Short upload outcome for toasts / REST:
	 * converted_avif | fallback_webp | converted_webp | failed_keep_original | companion_only | none
	 */
	public const UPLOAD_OUTCOME = '_menagerie_upload_outcome';
}
