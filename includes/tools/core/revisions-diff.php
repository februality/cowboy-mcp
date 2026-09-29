<?php
defined( 'ABSPATH' ) || exit;

/**
 * Unified-diff renderer for core's bundled Text_Diff (core ships only the
 * inline/table renderers). Loaded lazily by cowboy_mcp_revision_unified_diff()
 * AFTER wp-includes/wp-diff.php, because it extends Text_Diff_Renderer.
 */
class Cowboy_MCP_Unified_Diff_Renderer extends Text_Diff_Renderer {
	// phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore -- parent API.
	public $_leading_context_lines  = 2;
	public $_trailing_context_lines = 2;
	// phpcs:enable

	public function _blockHeader( $xbeg, $xlen, $ybeg, $ylen ) {
		return "@@ -{$xbeg},{$xlen} +{$ybeg},{$ylen} @@";
	}
	public function _context( $lines ) {
		return $this->_lines( $lines, ' ' );
	}
	public function _added( $lines ) {
		return $this->_lines( $lines, '+' );
	}
	public function _deleted( $lines ) {
		return $this->_lines( $lines, '-' );
	}
	public function _changed( $orig, $final ) {
		return $this->_deleted( $orig ) . $this->_added( $final );
	}
}
