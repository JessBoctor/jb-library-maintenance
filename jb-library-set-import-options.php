<?php
/**
 * Set the options to be used when importing new files
 * 
 * 'library-import-term-ids' => The 2-character stock codes which cans be used to set the 'doc_tags' terms
 * 'sds-post-content-and-excerpts' and 'tds-post-content-and-excerpts' => A record of the previous library's post content and excerpts
 *    The goal here is to apply the same content and excerpts to posts with matching file names
*/

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

 /**
 * Save the 2-character stock code prefixes and their corresponding tag slugs
 * Also, create the terms which should be used for each tag
 * 
 * Note: The stock code prefixes are set by Revchem. They are the first two characters of the 
 * SDS and TDS file names
 * 
 * We will use the prefixes as the term slugs, this way, when we add the files, we can set the terms
 * by pulling the stock code from the filename and passing it as a slug to in wp_set_object_terms
 *
 * Usage:
 *  wp save-stock-code-doc-tags
 *
 * @param none
 * @return void
 */
function create_stock_code_doc_tags(): void {
    // Make sure the doc_tags taxonomy is registered
    if ( ! taxonomy_exists( 'doc_tags' ) ) {
        WP_CLI::error( 'The DLP Document taxonomy "doc_tags" does not exists. New terms cannot be created.' );
    }

    $file_importer = new JB_Library_File_Importer();
    $stock_code_prefix_terms = $file_importer->get_stock_code_prefix_terms();

    WP_CLI::confirm( 'Ready to create the library document tags?', 'yes' );
    foreach( $stock_code_prefix_terms as $term_slug => $term_name ){
        $new_term = wp_insert_term(
            $term_name,
            'doc_tags',
            array(
                'slug' => $term_slug
            )
        );
        if ( is_wp_error( $new_term ) ){
            WP_CLI::error( "Term creation failed! A term was not created for slug {$term_slug} with name {$term_name}. Exiting.");
        }

        $term_id = $new_term['term_id'];
        WP_CLI::log( "New doc_tag term created! Term {$term_name} was successfully created with slug {$term_slug} and ID {$term_id}");
    }
    WP_CLI::log( "Term creation completed. Huzzah!");
}
WP_CLI::add_command( 'create-stock-code-doc-tags', 'create_stock_code_doc_tags' );
