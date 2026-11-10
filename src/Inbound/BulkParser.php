<?php
/**
 * Parses pasted receipt lines ("Hromadné pridanie").
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Inbound;

/**
 * Pure PHP (no WordPress) — unit tested.
 *
 * One item per line: `code;quantity[;batch[;expiry]]`, where code is a SKU
 * or an EAN. Columns may be separated by `;`, a tab (pasted from a
 * spreadsheet), `,` or — with only code and quantity — spaces. Empty lines
 * and lines starting with `#` are skipped, and so is a header line such as
 * `SKU;Počet;Šarža;Expirácia`. Expiry accepts every format of
 * ReceiptPayload::normalize_date().
 *
 * Lines are not merged here: the same code with another batch or expiry is
 * a separate receipt line (ReceiptPayload merges exact duplicates).
 */
final class BulkParser {

	public const MAX_LINES = ReceiptPayload::MAX_ITEMS;

	/** Words that mark the first line as a header. */
	private const HEADER_WORDS = array( 'sku', 'ean', 'kod', 'kód', 'code', 'produkt', 'product', 'kód/ean', 'sku/ean' );

	/**
	 * @param string $text Pasted text.
	 * @return array{rows: list<array{line: int, code: string, quantity: int, batch: string, expires_at: string}>, errors: list<array{field: string, reason: string, line: int|null, value: string, max: int|null}>}
	 */
	public static function parse( string $text ): array {
		$text   = preg_replace( '/^\xEF\xBB\xBF/', '', $text ) ?? $text;
		$lines  = preg_split( '/\r\n|\r|\n/', $text ) ?: array();
		$rows   = array();
		$errors = array();
		$seen   = false;

		foreach ( $lines as $index => $raw_line ) {
			$line    = $index + 1;
			$content = trim( $raw_line );

			if ( '' === $content || str_starts_with( $content, '#' ) ) {
				continue;
			}

			$columns = self::columns( $content );

			if ( ! $seen && self::is_header( $columns ) ) {
				$seen = true;
				continue;
			}

			$seen = true;

			if ( count( $rows ) >= self::MAX_LINES ) {
				$errors[] = ValidationException::error( 'lines', ValidationException::TOO_MANY_LINES, $line, '', self::MAX_LINES );
				break;
			}

			$row = self::row( $columns, $line, $errors );

			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		return array(
			'rows'   => $rows,
			'errors' => $errors,
		);
	}

	/**
	 * Splits a line into trimmed columns (trailing empty columns dropped).
	 *
	 * @return list<string>
	 */
	public static function columns( string $line ): array {
		if ( str_contains( $line, "\t" ) ) {
			$columns = explode( "\t", $line );
		} elseif ( str_contains( $line, ';' ) ) {
			$columns = explode( ';', $line );
		} elseif ( str_contains( $line, ',' ) ) {
			$columns = explode( ',', $line );
		} else {
			$columns = preg_split( '/\s+/', $line ) ?: array( $line );
		}

		$columns = array_map( static fn ( string $column ): string => trim( $column, " \t\"'" ), $columns );

		while ( array() !== $columns && '' === end( $columns ) ) {
			array_pop( $columns );
		}

		return array_values( $columns );
	}

	/**
	 * @param list<string> $columns Columns.
	 */
	private static function is_header( array $columns ): bool {
		$first  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $columns[0] ?? '', 'UTF-8' ) : strtolower( $columns[0] ?? '' );
		$second = $columns[1] ?? '';

		return in_array( $first, self::HEADER_WORDS, true ) && ! preg_match( '/^\d+$/', $second );
	}

	/**
	 * @param list<string>                                                                             $columns Columns.
	 * @param int                                                                                      $line    Line number.
	 * @param list<array{field: string, reason: string, line: int|null, value: string, max: int|null}> $errors  Errors.
	 * @return array{line: int, code: string, quantity: int, batch: string, expires_at: string}|null
	 */
	private static function row( array $columns, int $line, array &$errors ): ?array {
		if ( count( $columns ) > 4 ) {
			$errors[] = ValidationException::error( 'line', ValidationException::TOO_MANY_COLUMNS, $line );

			return null;
		}

		$before = count( $errors );
		$code   = $columns[0] ?? '';

		if ( '' === $code ) {
			$errors[] = ValidationException::error( 'code', ValidationException::REQUIRED, $line );
		} elseif ( strlen( $code ) > ReceiptPayload::MAX_SKU ) {
			$errors[] = ValidationException::error( 'code', ValidationException::TOO_LONG, $line, '', ReceiptPayload::MAX_SKU );
		}

		$quantity = ReceiptPayload::parse_quantity( $columns[1] ?? '', $reason );

		if ( null === $quantity ) {
			$errors[] = ValidationException::error( 'quantity', (string) $reason, $line, $columns[1] ?? '', ReceiptPayload::MAX_QUANTITY );
		}

		$batch = $columns[2] ?? '';

		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $batch, 'UTF-8' ) : strlen( $batch ) ) > ReceiptPayload::MAX_BATCH ) {
			$errors[] = ValidationException::error( 'batch', ValidationException::TOO_LONG, $line, '', ReceiptPayload::MAX_BATCH );
		}

		$expiry_raw = $columns[3] ?? '';
		$expires_at = '' === $expiry_raw ? '' : (string) ReceiptPayload::normalize_date( $expiry_raw );

		if ( '' !== $expiry_raw && '' === $expires_at ) {
			$errors[] = ValidationException::error( 'expires_at', ValidationException::INVALID_DATE, $line, $expiry_raw );
		}

		if ( count( $errors ) > $before ) {
			return null;
		}

		return array(
			'line'       => $line,
			'code'       => $code,
			'quantity'   => (int) $quantity,
			'batch'      => $batch,
			'expires_at' => $expires_at,
		);
	}
}
