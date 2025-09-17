<?php
/**
 * This class is used to import a single file into both the media library and the Document Library Pro plugin
 * The file has to be in the uploads directory already
 * It will also set the post content and excerpt based on the file name
 */
class JB_Library_File_Importer {
    /**
     * The file to be imported
     * @var string
     */
    public string $filepath = '';

    /**
     * An instance of the JB_PDF_Scraper class
     * @var JB_PDF_Scraper
     */
    public JB_PDF_Scraper $scraper;

    /**
     * The ID of the author to be set for the imported files
     * @var int
     */
    public int $author_id = 0;

    /**
     * The category slug for the imported files
     * @var int
     */
    public int $category_id = 0;

    /**
     * The tag slug for the product type based on the stock code
     * @var string
     */
    public string $tag_slug = '';

    /**
     * Constructor to initialize the stock code prefixes
     * @param string $category_slug The category slug to be used for the imported files
     */
    public function __construct( string $filepath = '', int $category_id = 0, int $author_id = 0 ) {
        $this->filepath = $filepath;
        $this->scraper = new JB_PDF_Scraper( $filepath );
        $this->category_id = $category_id;
        $this->author_id = ( 0 !== $author_id ) ? $author_id : get_current_user_id();
    }

    /**
     * Get the tag slug based on the stock code prefix which is the first two characters of the file name
     * @return string The tag slug
     */
    public function get_tag_slug_based_on_stock_code_prefix(): string {
        if ( empty( $this->filepath ) ) {
            return '';
        }

        $stock_code = substr( $this->file_name, 0, 2 );
        return isset( JB_LIBRARY_STOCKCODE_PREFIX_TERMS[ $stock_code ] )
            ? JB_LIBRARY_STOCKCODE_PREFIX_TERMS[ $stock_code ]
            : '';
    }

    /**
     * Import a file into the media library and the Document Library Pro plugin
     *
     * @param string $file_path The path to the file to import
     * @return string|WP_Error The DLP_Document post ID on success, or a WP_Error on failure
     */
    public function import_file( string $file_path ): null|string|WP_Error {
        $doctument_id = null;
        
        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( "File does not exist: $file_path" );
        }

        // Get the file name without the path
        $file_name = basename( $file_path );
        $file_type = mime_content_type( $file_path );

        // Import the file into the media library
        $attachment_id = wp_insert_attachment(
            array(
                'guid'           => $file_path,
                'post_mime_type' => $file_type,
                'post_title'     => sanitize_file_name( $file_name ),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ),
            $file_path
        );

        if ( is_wp_error( $attachment_id ) ) {
            return new WP_Error( "Failed to import file: " . $attachment_id->get_error_message() );
        }

        // Generate attachment metadata and update the attachment
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata( $attachment_id, $file_path );
        wp_update_attachment_metadata( $attachment_id, $attach_data );

        // Get the stock code from the file name
        $stock_code = substr( $file_name, 0, 2 );
        $this->tag_slug = isset( $this->stock_code_prefix_terms[ $stock_code ] ) ? $this->stock_code_prefix_terms[ $stock_code ] : '';

        // Create a new DLP_Document post
        $doctument_id = wp_insert_post(
            array(
                'post_title'   => sanitize_file_name( $file_name ),
                'post_content' => $this->scraper->is_pdf_readable ? $this->scraper->cleaned_text : '',
                'post_excerpt' => $this->get_document_excerpt(),
                'post_status'  => 'publish',
                'post_type'    => 'dlp_document',
                'post_author'  => $this->author_id,
                'tax_input'    => array(
                    'doc_categories' => $this->category_id ? array( $this->category_id ) : array(),
                    'doc_tags'       => $this->tag_slug ? array( $this->tag_slug ) : array(),
                    'doc_author'     => $this->author_id,
                    'file_type'      => $file_type,
                ),
                'meta_input'   => array(
                    '_dlp_document_link_type' => 'file',
                    '_dlp_attached_file_id'   => $attachment_id,
                    '_dlp_attached_file_name' => $file_name,
                    '_dlp_attachment_source'  => $file_path
                ),
            )
        );
   
        if ( is_wp_error( $doctument_id ) ) {
            return new WP_Error( "Failed to create DLP_Document post: " . $doctument_id->get_error_message() );
        }

        return $doctument_id;
    }


    /**
     * Get the excerpt content based on the file category
     * @return string The excerpt content
     */
    public function get_document_excerpt(): string {
        if ( false === $this->scraper->is_pdf_readable ) {
            return '';
        }

        // Determine if the file is an SDS or TDS based on the file name
        $file_name = basename( $this->filepath );
        if ( false !== stripos( $file_name, 'SDS' ) ) {
            return $this->get_sds_excerpt_content();
        } elseif ( false !== stripos( $file_name, 'TDS' ) ) {
            return $this->get_tds_excerpt_content();
        } else {
            // If we can't determine the type, return a snippet from the start of the document
            return wp_trim_excerpt( substr( $this->scraper->cleaned_text, 0, 300 ) );
        }
    }

    /**
     * Get the excerpt content for SDS PDFs
     * @return string The excerpt content
     */
    public function get_sds_excerpt_content(): string {
        if ( false === $this->scraper->is_pdf_readable ) {
            return '';
        }

        // Extract the Identification section by searching for the "Identification" and "Hazard" subtitles
        $identification_start_position = $this->scraper->find_substring_position("identification") + 14; // 14 is the length of the word "identification"
        $hazard_start_position = $this->scraper->find_substring_position("hazard");

        // If we can't find either subtitle, return an empty string
        if ( -1 === $identification_start_position || -1 === $hazard_start_position ) {
            return '';
        }

        // Sometimes, things get out of order, so we need to make sure the positions make sense
        if ( $identification_start_position > $hazard_start_position ) {
            $identification_start_position = 1;
        }

        // Figure out how long the "Identification" section is
        $section_text_length = $hazard_start_position - $identification_start_position;

        $identification_section = substr( $this->scraper->cleaned_text, $identification_start_position, $section_text_length );
        return wp_trim_excerpt( $identification_section );
    }

    /**
     * Get the excerpt content for SDS PDFs
     * THIS IS WIP UNTIL I FIGURE OUT HOW TO IDENTIFY PRODUCTS IN TDS FILES
     * @return string The excerpt content
     */
    public function get_tds_excerpt_content(): string {
        if ( false === $this->scraper->is_pdf_readable ) {
            return '';
        }

        // Extract the Identification section
        $identification_start = $this->scraper->find_substring_position("identification") + 14; // 14 is the length of the word "identification"
        $hazard_start = $this->scraper->find_substring_position("hazard");

        // If we can't find either section, return an empty string
        if ( -1 === $identification_start || -1 === $hazard_start ) {
            return '';
        }

        // Sometimes, things get out of order, so we need to make sure the positions make sense
        if ( $identification_start > $hazard_start ) {
            $identification_start = 1;
        }

        // Figure out how long the "Identification" section is
        $text_length = $hazard_start - $identification_start;

        $identification_section = substr( $cleaned_text, $identification_start, $hazard_start - $identification_start );
        return wp_trim_excerpt( $identification_section );
    }
}   
