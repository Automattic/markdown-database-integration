<?php
/** What a canonical file was when it was read, and whether that still holds. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A statement about one canonical file at one moment.
 *
 * Canonical files are edited by whatever the person at the keyboard likes —
 * git, an editor, another process — so anything remembered about a file is
 * only worth as much as the proof that the file still stands as it did. That
 * proof is expressed once, here, rather than restated by each caller that
 * wants to remember something, because a proof restated tends to be restated
 * more weakly.
 */
final class WP_Markdown_File_Witness {

	/** @param array<string,int> $identity */
	private function __construct(
		private readonly string $path,
		private readonly array $identity
	) {}

	/**
	 * Witness a file as it stands now.
	 *
	 * A link, or a name with more than one file behind it, cannot be spoken
	 * for, so nothing is witnessed.
	 */
	public static function take( string $path ): ?self {
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		if ( ! is_array( $stat ) || is_link( $path ) || 1 !== ( $stat['nlink'] ?? 1 ) ) {
			return null;
		}
		return new self(
			$path,
			array(
				'dev'   => (int) $stat['dev'],
				'ino'   => (int) $stat['ino'],
				'mode'  => (int) $stat['mode'],
				'size'  => (int) $stat['size'],
				'mtime' => (int) $stat['mtime'],
				'ctime' => (int) $stat['ctime'],
				'nlink' => (int) $stat['nlink'],
			)
		);
	}

	/** @return array<string,int> */
	public function identity(): array {
		return $this->identity;
	}

	public function is( ?self $other ): bool {
		return null !== $other && $this->path === $other->path && $this->identity === $other->identity;
	}

	/** Whether the file still stands as this witness found it. */
	public function holds(): bool {
		return $this->is( self::take( $this->path ) );
	}

	/** A form that can be written down and compared later. */
	public function state(): string {
		return implode( ':', $this->identity );
	}
}
