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
    public string $file_path = '';

    /**
     * The file name without the path
     * @var string
     */
    public string $file_name = '';

    /**
     * The file type (MIME type)
     * @var string
     */
    public string $file_type = '';

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
     * @var int The category ID, if set to 0 no category will be set
     */
    public int $category_id = 0;

    /**
     * The tag slug according to the prefix of the product stock code (e.g. filename)
     * @var string
     */
    public string $tag_slug = '';

    /**
     * The ID of the attachment post created in the media library
     * @var int
     */
    public int $attachment_id = 0;

    /**
     * The ID of the DLP_Document post created in the Document Library Pro plugin
     * @var int
     */
    public int $document_id = 0;

    /**
     * Constructor to initialize the stock code prefixes
     * @param string $category_slug The category slug to be used for the imported files
     */
    public function __construct( string $file_path, int $author_id = 0 ) {
        // Set file information
        $this->file_path = $file_path;
        $this->file_name = sanitize_file_name( basename( $this->file_path ) );
        $this->file_type = mime_content_type( $file_path );
        $this->scraper = new JB_PDF_Scraper( $file_path );

        // Set category, tag, and author information
        $this->set_category_id_based_on_file_name();
        $this->set_tag_slug_based_on_stock_code_prefix();
        $this->author_id = ( 0 !== $author_id ) ? $author_id : get_current_user_id();
    }

    /**
     * Get the category ID based on the cagtegory slug contained in the file name
     * @return void Sets the category ID
     */
    public function set_category_id_based_on_file_name(): void {
        if ( empty( $this->file_name ) ) {
            $this->file_name = sanitize_file_name( basename( $this->file_path ) );
        }

        // Set the term slug based on the file name
        if ( false !== stripos( $this->file_name, 'SDS' ) ) {
            $term_slug = 'safety-data-sheets';
        } elseif ( false !== stripos( $this->file_name, 'TDS' ) ) {
            $term_slug = 'technical-data-sheets';
        } else {
            // If we can't determine the type, return early
            return;
        }

        // Get the term by slug and set the category ID
        $term = get_term_by( 'slug', $term_slug, 'doc_categories', OBJECT );
        if ( $term ) {
            $this->category_id = $term->term_id;
        }
        return;
    }

    /**
     * Get the tag slug based on the stock code prefix which is the first two characters of the file name
     * @return void Sets the tag slug
     */
    public function set_tag_slug_based_on_stock_code_prefix(): void {
        $stock_code = substr( $this->file_name, 0, 2 );
        $this->tag_slug = isset( JB_LIBRARY_STOCKCODE_PREFIX_TERMS[ $stock_code ] )
            ? $stock_code
            : '';
        return;
    }

    /**
     * Import a file into the media library and the Document Library Pro plugin
     *
     * @return int|WP_Error The DLP_Document post ID on success, or a WP_Error on failure
     */
    public function import_file(): int|WP_Error {
        // Check if the file exists
        if ( ! file_exists( $this->file_path ) ) {
            return new WP_Error( "File does not exist: $this->file_path" );
        }

        // Import the file into the media library
        $attachment_id = wp_insert_attachment(
            array(
                'guid'           => $this->file_path,
                'post_mime_type' => $this->file_type,
                'post_title'     => $this->file_name,
                'post_content'   => '',
                'post_status'    => 'inherit',
            ),
            $this->file_path
        );

        if ( is_wp_error( $attachment_id ) ) {
            return new WP_Error( "Failed to import file: " . $attachment_id->get_error_message() );
        }
        // Store the attachment ID now that we are sure it isn't an error
        $this->attachment_id = $attachment_id;

        // Generate attachment metadata and update the attachment
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata( $attachment_id, $this->file_path );
        wp_update_attachment_metadata( $attachment_id, $attach_data );

        // Create a new DLP_Document post
        $document_id = wp_insert_post(
            array(
                'post_title'   => $this->file_name,
                'post_content' => $this->scraper->is_pdf_readable ? $this->scraper->cleaned_text : '',
                'post_excerpt' => $this->get_document_excerpt(),
                'post_status'  => 'publish',
                'post_type'    => 'dlp_document',
                'post_author'  => $this->author_id,
                'meta_input'   => array(
                    '_dlp_document_link_type' => 'file',
                    '_dlp_attached_file_id'   => $attachment_id,
                    '_dlp_attached_file_name' => $this->file_name,
                    '_dlp_attachment_source'  => $this->file_path
                ),
            )
        );

        if ( is_wp_error( $document_id ) ) {
            return new WP_Error( "Failed to create DLP_Document post: " . $doctument_id->get_error_message() );
        }

        // Set the document taxonomies if one was determined
        wp_set_object_terms( $document_id, $this->category_id, 'doc_categories', false );
        wp_set_object_terms( $document_id, $this->tag_slug, 'doc_tags', false );
        wp_set_object_terms( $document_id, $this->file_type, 'file_type', false );
        wp_set_object_terms( $document_id, $this->author_id, 'doc_author', false );

        // Store the document ID now that we are sure it isn't an error
        $this->document_id = $document_id;

        return $this->document_id;
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
        if ( false !== stripos( $this->file_name, 'SDS' ) ) {
            return $this->get_sds_excerpt_content();
        } elseif ( false !== stripos( $this->file_name, 'TDS' ) ) {
            return $this->get_tds_excerpt_content();
        } else {
            // If we can't determine the type, return a snippet from the start of the document
            return wp_trim_excerpt(
                trim(
                    substr( $this->scraper->cleaned_text, 0, 150 ),
                    "\t\n\r\0\x0B" // Trim common punctuation and whitespace
                )
            );
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
        return wp_trim_excerpt(
                trim(
                    $identification_section,
                    "\t\n\r\0\x0B" // Trim common punctuation and whitespace
                )
            );
    }

    /**
     * Get the excerpt content for SDS PDFs
     * @return string The excerpt content
     */
    public function get_tds_excerpt_content(): string {
        if ( false === $this->scraper->is_pdf_readable ) {
            return '';
        }

        // Extract some text based on the presence of certain keywords
        $search_terms = array(
            'features'      => $this->scraper->find_substring_position("features"),
            'description'   => $this->scraper->find_substring_position("description"),
            'benefits'      => $this->scraper->find_substring_position("benefits"),
            'eigenschaften' => $this->scraper->find_substring_position("eigenschaften"),
            'components'    => $this->scraper->find_substring_position("components"),
            'information'   => $this->scraper->find_substring_position("information"),
        );

        // Remove any terms that were not found (position -1)
        $positive_positions = array_diff( $search_terms, array( -1 ) );

        if ( empty( $positive_positions ) ) {
            // If no keywords are found, return a snippet from the start of the document
            return wp_trim_excerpt( substr( $this->scraper->cleaned_text, 0, 150 ) );
        }

        // Get the term with the earliest positive position
        $best_term = array_search( min( $positive_positions ), $positive_positions );
        $excerpt = substr( $this->scraper->cleaned_text, $search_terms[ $best_term ], 150 );
        return wp_trim_excerpt(
            trim(
                $excerpt,
                "\t\n\r\0\x0B" // Trim common punctuation and whitespace
            )
        );
    }
}   
