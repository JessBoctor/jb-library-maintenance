<?php
/**
 * This class is used to import a single file into both the media library and the Document Library Pro plugin
 * The file has to be in the uploads directory already
 * It will also set the post content and excerpt based on the file name
 */
class JB_Library_File_Importer {
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
    public array $stock_code_prefix_terms = array(
        '08' => 'VACUUM BAG SUPPLIES',
        '09' => 'RESIN EMULSIFIERS',
        '10' => 'ACETONE',
        '11' => 'ANVIL SLEEVES',
        '12' => 'ABRASIVES',
        '14' => 'RESPERATORS & MASKS',
        '15' => 'CLOTH / FABRICS',
        '16' => 'BRUSHES / BUFF SPURS',
        '17' => 'TOOLING RUBBER',
        '18' => 'BUFFING PADS',
        '19' => 'CATALYST',
        '20' => 'DURATEC PRODUCTS',
        '21' => 'DISPOSABLE CLOTHING',
        '22' => 'TOOLING BOARD',
        '23' => 'ADHESIVES',
        '24' => 'FILLERS',
        '25' => 'POLYURETHANE FOAM',
        '26' => 'MAT',
        '27' => 'MAT/WOVEN ROVING/ETC',
        '29' => 'MIXING CUPS',
        '30' => 'RESINS',
        '32' => 'RAGS',
        '34' => 'ALUMINUM TRI HYDRATE',
        '35' => 'TAPE',
        '36' => 'ROLLERS',
        '37' => 'WAXES',
        '38' => 'SHOP/MFG SUPPLIES/FLM',
        '39' => 'SOLVENT',
        '40' => 'GEL COATS',
        '44' => 'FLUORO PAINTS',
        '57' => 'FCS FINS SETS',
        '59' => 'FIN BOXES',
        '80' => 'EXPOY PRODUCTS',
        '81' => 'EPOXY PRODUCTS (SYS)',
        '82' => 'CORE MATERIAL',
        'CR' => 'COMPOSITE RESOURCES',
        'XX' => 'EQUIPMENTS',
    );

    /**
     * Constructor to initialize the stock code prefixes
     * @param string $category_slug The category slug to be used for the imported files
     */
    public function __construct( int $category_id = 0, int $author_id = 0 ) {
        $this->category_id = $category_id;
        $this->author_id = $author_id;
    }

    /**
     * Retrieve the stock code prefixes and their corresponding tag slugs
     * @return array The array of stock code prefixes and their corresponding tag slugs
     */
    public function get_stock_code_prefix_terms(): array {
        return $this->stock_code_prefix_terms;
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
