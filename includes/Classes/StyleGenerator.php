<?php
/**
 * Dynamic CSS generator for GSlider Blocks.
 *
 * Collects block styles during rendering, applies strict CSS sanitization
 * to prevent arbitrary CSS injection, enforces block-level scoping, and
 * outputs safe scoped styles inline.
 *
 * @package GSlider\Classes
 * @since   1.0.0
 */

namespace GSlider\Classes;

use GSlider\Interfaces\Registrable;
use GSlider\Traits\SingletonTrait;
use GSlider\Utils\CssSanitizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( StyleGenerator::class, false ) ) {

    /**
     * Generates and manages safe dynamic CSS for GSlider blocks.
     *
     * Collects styles from rendered blocks, sanitizes them to ensure
     * all rules are scoped to the block ID and free of arbitrary code,
     * and injects them via WordPress core inline style APIs.
     *
     * @since 1.0.0
     */
    class StyleGenerator implements Registrable {

        use SingletonTrait;

        /**
         * Collected and sanitized block styles keyed by block ID.
         *
         * @since 1.0.0
         *
         * @var string[]
         */
        private static $styles = [];

        /**
         * Track block IDs whose style tags have already been rendered on the current request.
         *
         * Prevents duplicate <style> tags for the same block ID (e.g. in query loops or synced patterns).
         *
         * @since 1.2.0
         *
         * @var array<string, bool>
         */
        private static $printed = [];

        /**
         * Track whether the LCP image priority has been applied to the first slide on the page.
         *
         * @since 1.2.0
         *
         * @var bool
         */
        private static $lcp_optimized = false;

        /**
         * Depth counter for headless renders that must keep inline styles inside REST responses.
         *
         * @since 1.2.0
         *
         * @var int
         */
        private static $headless_depth = 0;

        /**
         * Registers hooks for style collection, sanitization, and rendering.
         *
         * @since 1.0.0
         *
         * @return void
         */
        public function register(): void {
            add_filter( 'render_block', [ $this, 'collect_and_render_dynamic_styles' ], 10, 2 );
            add_action( 'wp_footer', [ $this, 'print_footer_styles' ], 20 );
            add_action( 'wp_enqueue_scripts', [ $this, 'register_style_handle' ], 20 );
        }

        /**
         * Registers the dynamic style handle for WordPress enqueue system and pre-collects styles (#314).
         *
         * @since 1.2.0
         *
         * @return void
         */
        public function register_style_handle(): void {
            if ( ! wp_style_is( 'gslider-blocks-dynamic-styles', 'registered' ) ) {
                wp_register_style( 'gslider-blocks-dynamic-styles', false, [], GSLIDER_VERSION );
                wp_enqueue_style( 'gslider-blocks-dynamic-styles' );
            }

            // In file mode on classic themes, pre-collect styles to avoid footer-late CSS (#314).
            $this->pre_collect_queried_post_styles();
        }

        /**
         * Pre-collects dynamic CSS files for the queried post during wp_enqueue_scripts (#314).
         *
         * On classic themes, block rendering occurs late during the_content (after wp_head has executed).
         * If dynamic block stylesheets are only enqueued during render_block, WordPress places the
         * <link rel="stylesheet"> tags in the footer (wp_footer), causing Flash of Unstyled Content (FOUC).
         *
         * When external file caching is enabled, this method pre-parses blocks in the queried singular post,
         * generates and saves any missing static CSS files to uploads, and calls wp_enqueue_style() early
         * so that all required block styles are placed in the <head>.
         *
         * Limitation Note:
         * Pre-collection parses blocks from the main queried post (is_singular()). Dynamic sliders rendered
         * outside the queried post (such as in sidebars, widget areas, or non-singular archive loops) will
         * fallback to being enqueued during render_block, which classic themes output in wp_footer.
         * Block themes (FSE) handle header style printing natively across all template parts.
         *
         * @since 1.2.1
         *
         * @return void
         */
        public function pre_collect_queried_post_styles(): void {
            if ( ! $this->is_file_cache_enabled() ) {
                return;
            }

            // Skip admin, REST requests, and feeds.
            if ( is_admin() || self::is_rest_request() || ( function_exists( 'is_feed' ) && is_feed() ) ) {
                return;
            }

            if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
                return;
            }

            if ( ! function_exists( 'get_post' ) || ! function_exists( 'parse_blocks' ) ) {
                return;
            }

            $post = get_post();
            if ( ! $post || empty( $post->post_content ) || ! is_string( $post->post_content ) ) {
                return;
            }

            // Fast exit if neither GSlider blocks nor synced pattern refs are present in post content.
            if ( false === strpos( $post->post_content, '<!-- wp:gslider-blocks/' )
                && false === strpos( $post->post_content, '<!-- wp:block ' ) ) {
                return;
            }

            $parsed_blocks = parse_blocks( $post->post_content );
            if ( ! empty( $parsed_blocks ) && is_array( $parsed_blocks ) ) {
                $this->collect_styles_from_block_list( $parsed_blocks );
            }
        }

        /**
         * Whether a core/block ref points to a published, non-password synced pattern (wp_block).
         *
         * Mirrors what core's core/block render callback would actually output, so drafts,
         * private/trashed patterns and arbitrary post IDs never get CSS written to disk.
         *
         * @since 1.2.1
         *
         * @param mixed $ref_post Result of get_post( $ref ).
         * @return bool
         */
        private static function is_renderable_synced_pattern( $ref_post ): bool {
            if ( ! is_object( $ref_post ) || empty( $ref_post->post_type ) || 'wp_block' !== $ref_post->post_type ) {
                return false;
            }
            if ( empty( $ref_post->post_status ) || 'publish' !== $ref_post->post_status ) {
                return false;
            }
            if ( function_exists( 'post_password_required' ) && post_password_required( $ref_post ) ) {
                return false;
            }
            return true;
        }

        /**
         * Recursively parses blocks to pre-enqueue dynamic CSS files for GSlider blocks (#314).
         *
         * Resolves inner blocks and core/block (synced patterns) to ensure sliders embedded
         * within synced patterns are also pre-enqueued into <head> on classic themes.
         *
         * @since 1.2.1
         *
         * @param array<int, array<string, mixed>> $blocks Parsed blocks array.
         * @param array<int, bool>                 $visited_refs Visited synced pattern post IDs for recursion protection.
         * @return void
         */
        private function collect_styles_from_block_list( array $blocks, array &$visited_refs = [] ): void {
            foreach ( $blocks as $block ) {
                if ( ! empty( $block['blockName'] ) && false !== strpos( $block['blockName'], 'gslider-blocks/' ) ) {
                    if ( ! empty( $block['attrs']['blockStyle'] ) ) {
                        $block_id = isset( $block['attrs']['blockId'] )
                            ? sanitize_key( $block['attrs']['blockId'] )
                            : 'gslider-blocks-' . md5( serialize( $block['attrs'] ) );

                        $raw_style   = is_string( $block['attrs']['blockStyle'] ) ? $block['attrs']['blockStyle'] : '';
                        $clean_style = $this->sanitize_css( $raw_style, $block_id );

                        if ( ! empty( $clean_style ) ) {
                            self::$styles[ $block_id ] = $clean_style;
                            $this->enqueue_dynamic_css_file( $block_id, $clean_style );
                        }
                    }
                }

                // Follow core/block (synced patterns / reusable blocks) to pre-enqueue nested sliders.
                if ( ! empty( $block['blockName'] ) && 'core/block' === $block['blockName'] && ! empty( $block['attrs']['ref'] ) ) {
                    $ref_id = (int) $block['attrs']['ref'];
                    if ( $ref_id > 0 && empty( $visited_refs[ $ref_id ] ) && function_exists( 'get_post' ) && function_exists( 'parse_blocks' ) ) {
                        $visited_refs[ $ref_id ] = true;
                        $ref_post = get_post( $ref_id );
                        if ( self::is_renderable_synced_pattern( $ref_post ) && ! empty( $ref_post->post_content ) && is_string( $ref_post->post_content ) ) {
                            if ( false !== strpos( $ref_post->post_content, '<!-- wp:gslider-blocks/' )
                                || false !== strpos( $ref_post->post_content, '<!-- wp:block ' ) ) {
                                $ref_blocks = parse_blocks( $ref_post->post_content );
                                if ( ! empty( $ref_blocks ) && is_array( $ref_blocks ) ) {
                                    $this->collect_styles_from_block_list( $ref_blocks, $visited_refs );
                                }
                            }
                        }
                    }
                }

                if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                    $this->collect_styles_from_block_list( $block['innerBlocks'], $visited_refs );
                }
            }
        }

        /**
         * Collects, sanitizes, and renders dynamic styles for GSlider blocks.
         *
         * Extracts the blockStyle attribute from each GSlider block,
         * strictly validates that all CSS rules are scoped to the block ID,
         * strips dangerous properties/protocols, and injects safe scoped CSS.
         *
         * @since 1.0.0
         *
         * @param string $block_content The block content being rendered.
         * @param array  $block         The full block data including attributes.
         * @return string The block content with safe scoped styles.
         */
        public function collect_and_render_dynamic_styles( $block_content, $block ) {
            if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
                return $block_content;
            }

            // Never print styles or touch markup inside RSS/Atom feeds (#312).
            if ( function_exists( 'is_feed' ) && is_feed() ) {
                return $block_content;
            }

            $headless = self::$headless_depth > 0;

            // Generic REST responses (e.g. content.rendered) get no inline CSS (#312), except
            // the plugin's own headless renders (BlockParser), which need per-slide CSS.
            if ( ! $headless && self::is_rest_request() ) {
                return $block_content;
            }

            if ( false === strpos( $block['blockName'], 'gslider-blocks/' ) ) {
                return $block_content;
            }

            if ( ! $headless ) {
                // Apply LCP image priority to the first slide image on the page (frontend only).
                $block_content = $this->optimize_lcp_image( $block_content );

                // Ensure explicit width and height attributes on slide images to eliminate CLS (#391).
                $block_content = $this->ensure_image_dimensions( $block_content, $block );
            }

            if ( empty( $block['attrs']['blockStyle'] ) ) {
                return $block_content;
            }

            $block_id = isset( $block['attrs']['blockId'] ) ?
                sanitize_key( $block['attrs']['blockId'] ) :
                'gslider-blocks-' . md5( serialize( $block['attrs'] ) );

            $raw_style = is_string( $block['attrs']['blockStyle'] ) ? $block['attrs']['blockStyle'] : '';

            // Strictly sanitize CSS to prevent arbitrary CSS injection.
            $clean_style = $this->sanitize_css( $raw_style, $block_id );

            if ( empty( $clean_style ) ) {
                return $block_content;
            }

            self::$styles[ $block_id ] = $clean_style;

            // Check if external file caching is enabled (#314).
            // Headless renders always use inline style tags so consumer gets per-slide CSS.
            if ( ! $headless && $this->is_file_cache_enabled() ) {
                $enqueued = $this->enqueue_dynamic_css_file( $block_id, $clean_style );
                if ( $enqueued ) {
                    return $block_content;
                }
            }

            // Headless slide HTML is consumed piece by piece, so every slide keeps its own style tag.
            // On normal pages, render the <style> tag only once per unique block ID per request.
            if ( ! $headless ) {
                if ( ! empty( self::$printed[ $block_id ] ) ) {
                    return $block_content;
                }
                self::$printed[ $block_id ] = true;
            }

            $nonce      = $this->get_csp_nonce();
            $nonce_attr = ! empty( $nonce ) ? ' nonce="' . esc_attr( $nonce ) . '"' : '';
            $style_tag  = '<style id="gslider-style-' . esc_attr( $block_id ) . '"' . $nonce_attr . '>' . $clean_style . '</style>';

            return $style_tag . $block_content;
        }

        /**
         * Renders a block for the plugin's headless REST output while keeping its inline styles.
         *
         * Used by BlockParser::normalize_flexi_content() so /gslider/v1 consumers still receive
         * per-slide CSS even though generic REST responses are stripped of inline styles.
         *
         * @since 1.2.0
         *
         * @param array $block Parsed block array.
         * @return string Rendered block HTML including scoped <style> tags.
         */
        public static function render_block_with_styles( array $block ): string {
            self::$headless_depth++;

            try {
                $html = render_block( $block );
            } finally {
                self::$headless_depth--;
            }

            return is_string( $html ) ? $html : '';
        }

        /**
         * Determines whether the current request is a WordPress REST API request.
         *
         * Deliberately does not use wp_is_json_request(), which is also true for
         * front-end AJAX loaders that send a JSON Accept header.
         *
         * @since 1.2.0
         *
         * @return bool True when serving a REST API request.
         */
        private static function is_rest_request(): bool {
            if ( function_exists( 'wp_is_serving_rest_request' ) ) {
                return (bool) wp_is_serving_rest_request();
            }

            return defined( 'REST_REQUEST' ) && REST_REQUEST;
        }

        /**
         * Prioritizes the first real slide image of the first GSlider slider on the page for LCP.
         *
         * Uses WP_HTML_Tag_Processor so attribute values are never rewritten as raw text.
         * Sets fetchpriority="high" and decoding="async", and switches loading="lazy" to
         * loading="eager". Images outside a slide and testimonial rating icons are skipped.
         *
         * @since 1.2.0
         *
         * @param string $block_content The block HTML content.
         * @return string Optimized block HTML content, or the original HTML when nothing applies.
         */
        public function optimize_lcp_image( string $block_content ): string {
            if ( self::$lcp_optimized || '' === $block_content ) {
                return $block_content;
            }

            // Only run on frontend page loads (skip admin, REST requests, and feeds).
            if ( is_admin() || self::is_rest_request() || ( function_exists( 'is_feed' ) && is_feed() ) ) {
                return $block_content;
            }

            if ( false === stripos( $block_content, '<img' ) || false === strpos( $block_content, 'swiper-slide' ) ) {
                return $block_content;
            }

            if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
                return $block_content;
            }

            /**
             * Filters whether to optimize the first slider image for LCP on the frontend.
             *
             * Return false to disable automatic fetchpriority="high", loading="eager"
             * and decoding="async" on the first slide image.
             *
             * @since 1.2.0
             *
             * @param bool   $optimize      Whether to optimize the LCP image. Default true.
             * @param string $block_content The block HTML content.
             */
            if ( ! apply_filters( 'gslider_lcp_optimize', true, $block_content ) ) {
                return $block_content;
            }

            $processor    = new \WP_HTML_Tag_Processor( $block_content );
            $in_slide     = false;
            $after_rating = false;
            $optimized    = false;

            while ( $processor->next_tag() ) {
                if ( $processor->is_tag_closer() ) {
                    continue;
                }

                $classes = $processor->get_attribute( 'class' );
                $classes = is_string( $classes ) ? ' ' . preg_replace( '/\s+/', ' ', $classes ) . ' ' : '';

                if ( 'IMG' !== $processor->get_tag() ) {
                    if ( false !== strpos( $classes, ' swiper-slide ' ) ) {
                        $in_slide = true;
                    }
                    // Testimonial rating images sit directly inside .gslider-testimonial-rating-icon.
                    $after_rating = false !== strpos( $classes, 'rating-icon' );
                    continue;
                }

                if ( ! $in_slide || $after_rating || false !== strpos( $classes, 'rating' ) ) {
                    $after_rating = false;
                    continue;
                }

                if ( null === $processor->get_attribute( 'fetchpriority' ) ) {
                    $processor->set_attribute( 'fetchpriority', 'high' );
                }
                if ( null === $processor->get_attribute( 'decoding' ) ) {
                    $processor->set_attribute( 'decoding', 'async' );
                }

                $loading = $processor->get_attribute( 'loading' );
                if ( is_string( $loading ) && 'lazy' === strtolower( $loading ) ) {
                    $processor->set_attribute( 'loading', 'eager' );
                }

                $optimized = true;
                break;
            }

            if ( ! $in_slide ) {
                return $block_content;
            }

            // The first slider on the page has been handled (with or without an image).
            self::$lcp_optimized = true;

            return $optimized ? $processor->get_updated_html() : $block_content;
        }

        /**
         * Ensures explicit width and height HTML attributes on slide images to eliminate CLS (#391).
         *
         * Uses WP_HTML_Tag_Processor (no regex) and only touches <img> tags inside
         * .swiper-slide whose src points to the same file as a block image/logo/author/rating item.
         * Dimensions come from the attachment size that matches the URL (full or an
         * intermediate size), so cropped sizes keep their real aspect ratio.
         *
         * @since 1.2.0
         *
         * @param string $block_content The block HTML content.
         * @param array  $block         The block data and attributes.
         * @return string Updated block HTML with width/height attributes on slide images.
         */
        public function ensure_image_dimensions( string $block_content, array $block ): string {
            if ( '' === $block_content || empty( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
                return $block_content;
            }

            if ( is_admin() || self::is_rest_request() || ( function_exists( 'is_feed' ) && is_feed() ) ) {
                return $block_content;
            }

            if ( false === stripos( $block_content, '<img' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
                return $block_content;
            }

            $items = [];
            if ( ! empty( $block['attrs']['images'] ) && is_array( $block['attrs']['images'] ) ) {
                $items = $block['attrs']['images'];
            } elseif ( ! empty( $block['attrs']['logos'] ) && is_array( $block['attrs']['logos'] ) ) {
                $items = $block['attrs']['logos'];
            } elseif ( ! empty( $block['attrs']['sliderItems'] ) && is_array( $block['attrs']['sliderItems'] ) ) {
                foreach ( $block['attrs']['sliderItems'] as $slide ) {
                    if ( ! empty( $slide['authorImage'] ) && is_array( $slide['authorImage'] ) ) {
                        $items[] = $slide['authorImage'];
                    }
                    if ( ! empty( $slide['ratingImage'] ) && is_array( $slide['ratingImage'] ) ) {
                        $items[] = $slide['ratingImage'];
                    }
                }
                if ( ! empty( $block['attrs']['quoteImageUrl'] ) && ! empty( $block['attrs']['quoteImageID'] ) ) {
                    $items[] = [
                        'id'  => $block['attrs']['quoteImageID'],
                        'url' => $block['attrs']['quoteImageUrl'],
                    ];
                }
            }

            // Map normalized URL path => attachment ID.
            $lookup = [];
            foreach ( $items as $item ) {
                if ( ! is_array( $item ) || empty( $item['id'] ) || ! is_numeric( $item['id'] ) || (int) $item['id'] <= 0 ) {
                    continue;
                }
                if ( empty( $item['url'] ) || ! is_string( $item['url'] ) ) {
                    continue;
                }
                $key = self::normalize_image_url( $item['url'] );
                if ( '' !== $key && ! isset( $lookup[ $key ] ) ) {
                    $lookup[ $key ] = (int) $item['id'];
                }
            }

            if ( empty( $lookup ) ) {
                return $block_content;
            }

            $processor = new \WP_HTML_Tag_Processor( $block_content );
            $in_slide  = false;
            $changed   = false;

            while ( $processor->next_tag() ) {
                if ( $processor->is_tag_closer() ) {
                    continue;
                }

                if ( 'IMG' !== $processor->get_tag() ) {
                    $classes = $processor->get_attribute( 'class' );
                    if ( is_string( $classes ) && false !== strpos( ' ' . preg_replace( '/\s+/', ' ', $classes ) . ' ', ' swiper-slide ' ) ) {
                        $in_slide = true;
                    }
                    continue;
                }

                if ( ! $in_slide ) {
                    continue;
                }

                $has_width  = null !== $processor->get_attribute( 'width' );
                $has_height = null !== $processor->get_attribute( 'height' );
                if ( $has_width && $has_height ) {
                    continue;
                }

                $src = $processor->get_attribute( 'src' );
                if ( ! is_string( $src ) || '' === $src ) {
                    continue;
                }

                $key = self::normalize_image_url( $src );
                if ( ! isset( $lookup[ $key ] ) ) {
                    continue;
                }

                $dimensions = self::get_image_dimensions_for_src( $src, $lookup[ $key ] );
                if ( null === $dimensions ) {
                    continue;
                }

                // Only add what is missing; never overwrite author values.
                if ( ! $has_width ) {
                    $processor->set_attribute( 'width', (string) $dimensions[0] );
                }
                if ( ! $has_height ) {
                    $processor->set_attribute( 'height', (string) $dimensions[1] );
                }
                $changed = true;
            }

            return $changed ? $processor->get_updated_html() : $block_content;
        }

        /**
         * Normalizes an image URL to "host/path" (no scheme, query or fragment) for matching.
         *
         * @since 1.2.0
         *
         * @param string $url Image URL.
         * @return string Normalized key, or '' when the URL has no path.
         */
        private static function normalize_image_url( string $url ): string {
            $url   = html_entity_decode( trim( $url ), ENT_QUOTES );
            $parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
            if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
                return '';
            }
            $host = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
            return $host . '/' . ltrim( $parts['path'], '/' );
        }

        /**
         * Returns [ width, height ] for the attachment size that the src points to.
         *
         * Uses wp_image_src_get_dimensions() (WP 5.5+) which matches the file name
         * against the full image and every intermediate size. Falls back to the
         * full-size metadata only when the src file name is the original file.
         *
         * @since 1.2.0
         *
         * @param string $src           Image src from the markup.
         * @param int    $attachment_id Attachment ID.
         * @return int[]|null Width and height, or null when unknown.
         */
        private static function get_image_dimensions_for_src( string $src, int $attachment_id ): ?array {
            if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
                return null;
            }
            $meta = wp_get_attachment_metadata( $attachment_id );
            if ( ! is_array( $meta ) ) {
                return null;
            }

            $path = function_exists( 'wp_parse_url' ) ? wp_parse_url( $src, PHP_URL_PATH ) : parse_url( $src, PHP_URL_PATH );
            if ( ! is_string( $path ) || '' === $path ) {
                return null;
            }
            $src_path = $path;

            if ( function_exists( 'wp_image_src_get_dimensions' ) ) {
                $dims = wp_image_src_get_dimensions( $src_path, $meta, $attachment_id );
                if ( is_array( $dims ) && ! empty( $dims[0] ) && ! empty( $dims[1] ) ) {
                    return [ (int) $dims[0], (int) $dims[1] ];
                }
                return null;
            }

            // Fallback: match file names ourselves.
            $file = basename( $src_path );
            if ( ! empty( $meta['file'] ) && is_string( $meta['file'] ) && basename( $meta['file'] ) === $file
                && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
                return [ (int) $meta['width'], (int) $meta['height'] ];
            }
            if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
                foreach ( $meta['sizes'] as $size ) {
                    if ( is_array( $size ) && isset( $size['file'] ) && $size['file'] === $file
                        && ! empty( $size['width'] ) && ! empty( $size['height'] ) ) {
                        return [ (int) $size['width'], (int) $size['height'] ];
                    }
                }
            }
            return null;
        }

        /**
         * Backward-compatible alias for collect_dynamic_styles.
         *
         * @since 1.0.0
         *
         * @param string $block_content The block content being rendered.
         * @param array  $block         The full block data including attributes.
         * @return string The block content with safe scoped styles.
         */
        public function collect_dynamic_styles( $block_content, $block ) {
            return $this->collect_and_render_dynamic_styles( $block_content, $block );
        }

        /**
         * Strictly sanitizes CSS rules to prevent arbitrary CSS injection.
         *
         * Enforces:
         * 1. Removal of all HTML/XML tags.
         * 2. Removal of comments to avoid filter bypasses.
         * 3. Blocking of dangerous protocols (javascript, data, expression, behavior, -moz-binding, vbscript).
         * 4. Stripping of risky at-rules (@import, @charset, @namespace, etc.).
         * 5. Sanitization of url() values to only allow safe HTTP/HTTPS image URLs.
         * 6. Scoping: every selector MUST contain the specific block ID.
         *
         * @since 1.2.0
         *
         * @param string $css      The raw CSS string to sanitize.
         * @param string $block_id The unique block identifier to enforce scoping against.
         * @return string The sanitized and minified CSS string.
         */
        public function sanitize_css( $css, $block_id ): string {
            if ( empty( $css ) || ! is_string( $css ) ) {
                return '';
            }

            // Layer 1: Run comprehensive CssSanitizer to strip nested exploit patterns.
            $css = CssSanitizer::sanitize( $css );
            if ( empty( $css ) ) {
                return '';
            }

            // 1. Strip all HTML/XML tags.
            $css = wp_strip_all_tags( $css );

            // 2. Strip comments to prevent comment-based filter bypasses.
            $css = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css );

            // 3. Block and strip dangerous CSS functions, protocols, and keywords.
            $dangerous_patterns = [
                '/javascript\s*:/i',
                '/expression\s*\(/i',
                '/behavior\s*:/i',
                '/-moz-binding\s*:/i',
                '/vbscript\s*:/i',
                '/@import\b[^;]*;?/i',
                '/@charset\b[^;]*;?/i',
                '/@namespace\b[^;]*;?/i',
                '/<\/?(?:script|style|iframe|object|embed)[^>]*>/i',
                '/data\s*:\s*text\/html/i',
            ];
            $css = preg_replace( $dangerous_patterns, '', $css );

            // 4. Sanitize and validate url(...) values: only allow safe image URLs.
            $css = preg_replace_callback( '/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/i', function( $matches ) {
                $url = trim( $matches[2] );
                // If it contains quotes, parentheses, semicolons, or dangerous protocols, reject it.
                if ( preg_match( '/[\'"\(\);\s]/', $url ) || preg_match( '/^(javascript|data|vbscript):/i', $url ) ) {
                    return 'none';
                }
                $clean_url = esc_url_raw( $url );
                return empty( $clean_url ) ? 'none' : "url('{$clean_url}')";
            }, $css );

            // 5. Enforce Block Scoping:
            // Extract media queries and normal rules, ensuring EVERY selector is scoped to $block_id.
            $output = '';

            // Handle media queries.
            $media_regex = '/@media[^{]+\{(?:[^{}]*\{[^{}]*\})+[^{}]*\}/i';
            if ( preg_match_all( $media_regex, $css, $media_matches, PREG_SET_ORDER ) ) {
                foreach ( $media_matches as $match ) {
                    $media_full   = $match[0];
                    $open_pos     = strpos( $media_full, '{' );
                    $media_header = trim( substr( $media_full, 0, $open_pos ) );
                    $media_inner  = trim( substr( $media_full, $open_pos + 1, -1 ) );

                    $sanitized_inner = $this->filter_rules_by_scope( $media_inner, $block_id );
                    if ( ! empty( $sanitized_inner ) ) {
                        $output .= $media_header . ' { ' . $sanitized_inner . " }\n";
                    }
                    $css = str_replace( $media_full, '', $css );
                }
            }

            // Handle remaining standard rules.
            $sanitized_normal = $this->filter_rules_by_scope( $css, $block_id );
            if ( ! empty( $sanitized_normal ) ) {
                $output .= $sanitized_normal;
            }

            return $this->minify_css( $output );
        }

        /**
         * Filters CSS rules so that only selectors containing the specific block ID are retained.
         *
         * Drops any selectors that attempt to target outside elements (e.g. body, html, input, *).
         *
         * @since 1.2.0
         *
         * @param string $css      CSS rules chunk.
         * @param string $block_id Block identifier to match against.
         * @return string Filtered CSS rules.
         */
        private function filter_rules_by_scope( $css, $block_id ): string {
            $rules       = explode( '}', $css );
            $valid_rules = [];

            foreach ( $rules as $rule ) {
                $rule = trim( $rule );
                if ( empty( $rule ) ) {
                    continue;
                }

                $parts = explode( '{', $rule );
                if ( count( $parts ) < 2 ) {
                    continue;
                }

                $selectors_str = trim( $parts[0] );
                $props_str     = trim( $parts[1] );

                if ( empty( $selectors_str ) || empty( $props_str ) ) {
                    continue;
                }

                $selectors       = explode( ',', $selectors_str );
                $valid_selectors = [];

                foreach ( $selectors as $sel ) {
                    $sel = trim( $sel );
                    // Must contain the specific block ID to prevent targeting arbitrary page elements.
                    if ( false !== strpos( $sel, $block_id ) ) {
                        $valid_selectors[] = $sel;
                    }
                }

                if ( ! empty( $valid_selectors ) ) {
                    $valid_rules[] = implode( ', ', $valid_selectors ) . ' { ' . $props_str . ' }';
                }
            }

            return implode( ' ', $valid_rules );
        }

        /**
         * Resets collected styles and optimization flags in memory (primarily for unit testing).
         *
         * @since 1.2.0
         *
         * @return void
         */
        public static function reset_styles(): void {
            self::$styles        = [];
            self::$printed       = [];
            self::$lcp_optimized = false;
        }

        /**
         * Gets collected styles array (primarily for unit testing).
         *
         * @since 1.2.0
         *
         * @return string[]
         */
        public static function get_collected_styles(): array {
            return self::$styles;
        }

        /**
         * Retrieves the Content Security Policy (CSP) nonce if configured (#314, #391).
         *
         * Checks the 'gslider_style_nonce', 'gslider_csp_nonce', and third-party 'wp_csp_nonce' filters.
         *
         * Compatibility Note:
         * 'wp_csp_nonce' is widely used by popular security plugins, but is NOT a standard WordPress core filter.
         *
         * CSP Level 3 Consideration:
         * Injecting nonces onto dynamic <style> tags and enqueuing external CSS files allows sites to comply
         * with strict 'style-src' policies without 'unsafe-inline'. However, any block using inline HTML
         * style="" attributes (such as the star rating width percentage in Testimonial blocks) still requires
         * 'style-src-attr' (e.g. 'unsafe-inline' or attribute hash) unless rewritten.
         *
         * @since 1.2.1
         *
         * @return string The sanitized CSP nonce string, or empty string if not set.
         */
        public function get_csp_nonce(): string {
            $nonce = apply_filters( 'gslider_style_nonce', '' );
            if ( empty( $nonce ) ) {
                $nonce = apply_filters( 'gslider_csp_nonce', '' );
            }
            if ( empty( $nonce ) ) {
                $nonce = apply_filters( 'wp_csp_nonce', '' );
            }
            return is_string( $nonce ) ? sanitize_text_field( $nonce ) : '';
        }

        /**
         * Determines whether external dynamic CSS file caching is enabled (#314).
         *
         * Reads get_option('gslider_settings')['css_delivery']. A missing saved option
         * defaults to 'inline' so existing installations remain unchanged without writing
         * to disk. Only when explicitly saved as 'file' is file caching enabled.
         *
         * Can be overridden via constant GSLIDER_DYNAMIC_CSS_FILE_CACHE or filter 'gslider_enable_file_cache'.
         *
         * @since 1.2.1
         *
         * @return bool True if file caching is enabled.
         */
        public function is_file_cache_enabled(): bool {
            $settings = function_exists( 'get_option' ) ? get_option( 'gslider_settings', [] ) : [];
            $delivery = ( is_array( $settings ) && ! empty( $settings['css_delivery'] ) )
                ? (string) $settings['css_delivery']
                : 'inline';

            $enabled = ( 'file' === $delivery );

            if ( defined( 'GSLIDER_DYNAMIC_CSS_FILE_CACHE' ) ) {
                $enabled = (bool) GSLIDER_DYNAMIC_CSS_FILE_CACHE;
            }

            return (bool) apply_filters( 'gslider_enable_file_cache', $enabled );
        }

        /**
         * Writes dynamic CSS to a hashed static file in uploads and enqueues it (#314).
         *
         * Allows strict CSP environments (style-src 'self') to load dynamic block styles
         * without any inline <style> tags.
         *
         * Note on Cache Retention:
         * Older hashed CSS files for the same block ID are deliberately NOT deleted at render time.
         * Deleting previous hashes when a block's CSS changes would cause 404 errors for visitors receiving
         * cached HTML from full-page caching plugins or CDN edge caches. Old files are retained until an
         * administrator explicitly triggers a cache purge via the "Clear CSS cache" tool in the dashboard.
         *
         * @since 1.2.1
         *
         * @param string $block_id    Block unique identifier.
         * @param string $clean_style Sanitized CSS rules.
         * @return bool True if successfully enqueued or file exists, false to fallback to inline style.
         */
        public function enqueue_dynamic_css_file( string $block_id, string $clean_style ): bool {
            if ( empty( $block_id ) || empty( $clean_style ) ) {
                return false;
            }

            if ( ! function_exists( 'wp_upload_dir' ) ) {
                return false;
            }

            $upload = wp_upload_dir();
            if ( empty( $upload['basedir'] ) || empty( $upload['baseurl'] ) ) {
                return false;
            }

            $cache_dir = trailingslashit( $upload['basedir'] ) . 'gslider-styles';
            $cache_url = trailingslashit( $upload['baseurl'] ) . 'gslider-styles';

            if ( ! is_dir( $cache_dir ) ) {
                if ( function_exists( 'wp_mkdir_p' ) ) {
                    wp_mkdir_p( $cache_dir );
                } elseif ( ! @mkdir( $cache_dir, 0755, true ) && ! is_dir( $cache_dir ) ) {
                    return false;
                }
            }

            $hash      = substr( md5( $clean_style ), 0, 8 );
            $file_name = 'gslider-' . $block_id . '-' . $hash . '.css';
            $file_path = $cache_dir . '/' . $file_name;
            $file_url  = function_exists( 'set_url_scheme' )
                ? set_url_scheme( $cache_url . '/' . $file_name )
                : $cache_url . '/' . $file_name;

            if ( ! file_exists( $file_path ) ) {
                // Silence directory listing on servers without autoindex disabled.
                $index_file = $cache_dir . '/index.php';
                if ( ! file_exists( $index_file ) ) {
                    @file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
                }

                // Write to a unique temp file, then rename atomically so a concurrent
                // request never enqueues a truncated or half-written stylesheet.
                $tmp_path = $file_path . '.' . uniqid( '', true ) . '.tmp';
                $written  = @file_put_contents( $tmp_path, $clean_style, LOCK_EX );
                if ( false === $written || strlen( $clean_style ) !== $written ) {
                    @unlink( $tmp_path );
                    return false;
                }
                if ( ! @rename( $tmp_path, $file_path ) ) {
                    @unlink( $tmp_path );
                    if ( ! file_exists( $file_path ) ) {
                        return false;
                    }
                }
            }

            $handle = 'gslider-style-' . $block_id;
            if ( function_exists( 'wp_enqueue_style' ) ) {
                wp_enqueue_style( $handle, $file_url, [], GSLIDER_VERSION );
            }

            return true;
        }

        /**
         * Clears all cached dynamic CSS files in uploads/gslider-styles (#314).
         *
         * Purges all static block CSS files from disk and resets in-memory styles.
         *
         * Important:
         * After clearing the dynamic CSS cache, any full-page caching plugins (e.g. WP Super Cache,
         * W3 Total Cache, WP Rocket) or CDN edge caches (Cloudflare, Fastly) serving cached HTML pages
         * must be purged, otherwise visitors receiving cached HTML may request deleted CSS files.
         *
         * @since 1.2.0
         *
         * @return int Number of cached files purged.
         */
        public function clear_dynamic_css_cache(): int {
            self::reset_styles();

            if ( ! function_exists( 'wp_upload_dir' ) ) {
                return 0;
            }

            $upload = wp_upload_dir();
            if ( empty( $upload['basedir'] ) ) {
                return 0;
            }

            $cache_dir = trailingslashit( $upload['basedir'] ) . 'gslider-styles';
            if ( ! is_dir( $cache_dir ) ) {
                return 0;
            }

            $files = glob( $cache_dir . '/*.css' );
            if ( empty( $files ) || ! is_array( $files ) ) {
                return 0;
            }

            $count = 0;
            foreach ( $files as $file ) {
                if ( is_file( $file ) && @unlink( $file ) ) {
                    $count++;
                }
            }

            return $count;
        }

        /**
         * Retrieves dynamic CSS cache statistics (#314).
         *
         * @since 1.2.0
         *
         * @return array{files_count: int, total_size_bytes: int, total_size_formatted: string}
         */
        public function get_cache_stats(): array {
            $default = [
                'files_count'          => 0,
                'total_size_bytes'     => 0,
                'total_size_formatted' => '0 KB',
            ];

            if ( ! function_exists( 'wp_upload_dir' ) ) {
                return $default;
            }

            $upload = wp_upload_dir();
            if ( empty( $upload['basedir'] ) ) {
                return $default;
            }

            $cache_dir = trailingslashit( $upload['basedir'] ) . 'gslider-styles';
            if ( ! is_dir( $cache_dir ) ) {
                return $default;
            }

            $files = glob( $cache_dir . '/*.css' );
            if ( empty( $files ) || ! is_array( $files ) ) {
                return $default;
            }

            $total_bytes = 0;
            $files_count = 0;
            foreach ( $files as $file ) {
                if ( is_file( $file ) ) {
                    $files_count++;
                    $total_bytes += (int) filesize( $file );
                }
            }

            $formatted = function_exists( 'size_format' )
                ? size_format( $total_bytes, 2 )
                : round( $total_bytes / 1024, 2 ) . ' KB';

            return [
                'files_count'          => $files_count,
                'total_size_bytes'     => $total_bytes,
                'total_size_formatted' => $formatted,
            ];
        }

        /**
         * Prints any collected dynamic styles in wp_footer as fallback.
         *
         * If styles remain collected and CSP nonce is present, injects nonce attribute.
         *
         * @since 1.0.0
         *
         * @return void
         */
        public function print_footer_styles(): void {
            if ( empty( self::$styles ) || $this->is_file_cache_enabled() ) {
                self::$styles = [];
                return;
            }

            // Only output dynamic styles that have not already been rendered inline.
            $unprinted_styles = array_diff_key( self::$styles, self::$printed );
            if ( ! empty( $unprinted_styles ) ) {
                $combined_css = implode( "\n", $unprinted_styles );
                if ( ! empty( $combined_css ) ) {
                    $nonce      = $this->get_csp_nonce();
                    $nonce_attr = ! empty( $nonce ) ? ' nonce="' . esc_attr( $nonce ) . '"' : '';
                    echo '<style id="gslider-blocks-footer-styles"' . $nonce_attr . ">\n" . $combined_css . "\n</style>\n";
                }
            }

            self::$styles = [];
        }

        /**
         * Minifies a CSS string.
         *
         * Removes comments, unnecessary whitespace, and trailing
         * semicolons before closing braces.
         *
         * @since 1.0.0
         *
         * @param string $css The raw CSS string to minify.
         * @return string The minified CSS string.
         */
        private function minify_css( $css ): string {
            if ( empty( $css ) ) {
                return '';
            }

            $css = wp_strip_all_tags( $css );
            $css = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css );
            $css = preg_replace( '/\s*([:,;{}])\s*/', '$1', $css );
            $css = preg_replace( '/;}/', '}', $css );

            return trim( $css );
        }
    }
}
