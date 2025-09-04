<?php
/**
 * This class is used to import a single file into both the media library and the Document Library Pro plugin
 * The file has to be in the uploads directory already
 * It will also set the post content and excerpt based on the file name
 */
class JB_Library_Import_Files {
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
     * The array of stock code prefixes and their corresponding tag slugs
     * @var array
     */
    public array $stock_code_prefix_terms = array();

    

    /**
     * Constructor to initialize the stock code prefixes
     * @param string $category_slug The category slug to be used for the imported files
     */
    public function __construct( int $category_id, int $author_id = 0 ) {
        $this->stock_code_prefix_terms = get_option( 'library-import-term-ids', array() );
        $this->category_id = $category_id;
        $this->author_id = $author_id;
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

        // Scrape the content from the file name
        // To-do: Implement actual PDF text scraping logic in a separate class

        // Get the stock code from the file name
        $stock_code = substr( $file_name, 0, 2 );
        $this->tag_slug = isset( $this->stock_code_prefix_terms[ $stock_code ] ) ? $this->stock_code_prefix_terms[ $stock_code ] : '';

        // Create a new DLP_Document post
        $doctument_id = wp_insert_post(
            array(
                'post_title'   => sanitize_file_name( $file_name ),
                'post_content' => '', // Scraped content can be set here if needed
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
}   
