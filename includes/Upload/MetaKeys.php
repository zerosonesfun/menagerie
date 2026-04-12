<?php
/**
 * Post meta keys shared by upload handlers.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie\Upload;

if (! defined('ABSPATH')) {
	exit;
}

final class MetaKeys {
	public const PROCESSED = '_menagerie_processed';

	public const ORIGINAL_BYTES = '_menagerie_original_bytes';

	public const OPTIMIZED_BYTES = '_menagerie_optimized_bytes';

	public const MIME_OUT = '_menagerie_mime_out';

	public const PROCESSED_AT = '_menagerie_processed_at';

	public const SERVER_FALLBACK = '_menagerie_server_fallback';
}
