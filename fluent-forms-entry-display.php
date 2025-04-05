<?php
/**
 * Plugin Name:     Fluent Forms Entry Display
 * Plugin URI:      https://elodpal.ro
 * Description:     Displays Fluent Forms entries for multiple form IDs in a single table via a shortcode [ff_entries_table form_ids="ID1,ID2,ID3" fields="field1,field2" labels="Label 1,Label 2"]. Assumes you provide matching fields and labels for each language.
 * Version:         1.1.3
 * Author:          Elod Pal
 * Author URI:      https://elodpal.ro
 * License:         GPL v2 or later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:     fluent-forms-entry-display
 * Domain Path:     /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Registers the shortcode [ff_entries_table]
 */
function ffed_register_shortcode() {
    add_shortcode( 'ff_entries_table', 'ffed_display_entries_shortcode_callback' );
}
add_action( 'init', 'ffed_register_shortcode' );

/**
 * The callback function for the [ff_entries_table] shortcode.
 *
 * @param array $atts Shortcode attributes. Expects 'form_ids' (comma-separated).
 * @return string HTML output for the table or an error message.
 */
function ffed_display_entries_shortcode_callback( $atts ) {
    global $wpdb; // Access WordPress database methods

    // --- 1. Process Shortcode Attributes ---
    $atts = shortcode_atts(
        array(
            'form_ids'      => '', // Default: Empty string. Expects comma-separated IDs.
            'fields'        => '', // Optional: Comma-separated list of specific field input names to display
            'labels'        => '', // Optional: Comma-separated list of labels for the specified 'fields' (translatable)
            'show_date'     => 'true', // Optional: Show submission date column ('true' or 'false')
            'date_label'    => 'Submitted At', // Optional: Label for the date column (translatable)
            'show_count'    => 'true', // Optional: Show the total number of entries below the table
            'count_text'    => pll__('Total Entries:'), // Optional: Text to display before the count (translatable)
            'enable_search' => 'true', // Optional: Enable live search functionality
            'search_label'  => pll__('Search Names:') // Optional: Label for the search input (translatable)
        ),
        $atts,
        'ff_entries_table' // The shortcode tag
    );

    // Validate and sanitize form_ids (same as before)
    if ( empty( $atts['form_ids'] ) ) {
        return '<p style="color: red;">' . esc_html__( 'Error: Please provide the form_ids attribute with comma-separated numeric form IDs. Example: [ff_entries_table form_ids="123,456,789"]', 'fluent-forms-entry-display' ) . '</p>';
    }

    $form_id_strings = explode( ',', $atts['form_ids'] );
    $valid_form_ids = array_map( 'intval', $form_id_strings );
    $valid_form_ids = array_filter( $valid_form_ids, function($id) { return $id > 0; } );

    if ( empty( $valid_form_ids ) ) {
        return '<p style="color: red;">' . esc_html__( 'Error: No valid numeric form IDs found in the form_ids attribute. Please provide comma-separated numeric IDs.', 'fluent-forms-entry-display' ) . '</p>';
    }

    $show_date = filter_var($atts['show_date'], FILTER_VALIDATE_BOOLEAN);
    $date_label = sanitize_text_field(__( $atts['date_label'], 'fluent-forms-entry-display' ));
    $show_count = filter_var($atts['show_count'], FILTER_VALIDATE_BOOLEAN);
    $count_text = sanitize_text_field(__( $atts['count_text'], 'fluent-forms-entry-display' ));
    $enable_search = filter_var($atts['enable_search'], FILTER_VALIDATE_BOOLEAN);
    $search_label = sanitize_text_field(__( $atts['search_label'], 'fluent-forms-entry-display' ));

    // --- 2. Define Table Names ---
    $submissions_table = $wpdb->prefix . 'fluentform_submissions';

    // --- 3. Query the Database for multiple Form IDs --- (same as before)
    $placeholders = implode( ', ', array_fill( 0, count( $valid_form_ids ), '%d' ) );
    $query = $wpdb->prepare(
        "SELECT id, response, created_at, form_id FROM {$submissions_table} WHERE form_id IN ( " . $placeholders . " ) ORDER BY created_at DESC",
        $valid_form_ids
    );
    $results = $wpdb->get_results( $query );

    // --- 4. Check if any entries were found --- (same as before)
    if ( empty( $results ) ) {
        return '<p>' . sprintf( esc_html__( 'No entries found for the specified form IDs (%s).', 'fluent-forms-entry-display' ), esc_html( implode(', ', $valid_form_ids) ) ) . '</p>';
    }

    // --- 5. Process Specific Fields and Labels (if provided) ---
    $display_fields = array();
    $display_labels = array();
    $use_specific_fields = false;
    $name_column_index = false; // Initialize to false, will be updated if 'names' field is displayed

    if (!empty($atts['fields'])) {
        $display_fields = array_map('trim', explode(',', $atts['fields']));
        if (!empty($atts['labels'])) {
            $display_labels = array_map(function($label) {
                return trim(__( $label, 'fluent-forms-entry-display' ));
            }, explode(',', $atts['labels']));
            if (count($display_labels) != count($display_fields)) {
                return '<p style="color: red;">' . esc_html__( 'Error: The number of labels must match the number of fields specified.', 'fluent-forms-entry-display' ) . '</p>';
            }
        } else {
            $display_labels = array_map(function($key) {
                return ucwords(str_replace(array('_', '-'), ' ', $key)); // Note: Field keys themselves are usually not user-facing and might not need translation in this context.
            }, $display_fields);
        }
        $use_specific_fields = true;
        $name_column_index = array_search('names', $display_fields);
    }

    // --- 6. Prepare Table Headers ---
    $headers = array();
    $first_response_data = null;

    foreach ($results as $first_result_candidate) {
        $first_response_data = json_decode( $first_result_candidate->response, true );
        if (is_array($first_response_data)) {
            break;
        }
    }

    if ($use_specific_fields) {
        $headers = $display_labels;
    } elseif (is_array($first_response_data)) {
        $headers = array_map(function($key) {
            return ucwords(str_replace(array('_', '-'), ' ', $key));
        }, array_keys($first_response_data));
        $name_column_index = array_search('Names', $headers); // Assuming auto-generated header will be 'Names'
    } else {
        return '<p style="color: red;">' . esc_html__( 'Error: Could not determine table headers. Either specify fields/labels attributes or ensure form entries exist and have valid data.', 'fluent-forms-entry-display' ) . '</p>';
    }

    if ($show_date) {
        $headers[] = esc_html($date_label);
    }

    // --- 7. Build the HTML Table ---
    ob_start();
    ?>
    <div class="ffed-table-wrapper">
        <?php if ($enable_search && $name_column_index !== false) : ?>
            <div class="ffed-search-container" style="margin-bottom: 10px;">
                <label for="ffed-search-input"><?php echo esc_html($search_label); ?></label>
                <input type="text" id="ffed-search-input" placeholder="<?php esc_attr_e(pll__('Enter name...'), 'fluent-forms-entry-display'); ?>">
            </div>
        <?php endif; ?>
        <table class="ffed-entries-table">
            <thead>
                <tr>
                    <?php foreach ( $headers as $header ) : ?>
                        <th><?php echo esc_html( $header ); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $results as $row ) : ?>
                    <?php $response_data = json_decode( $row->response, true ); ?>
                    <?php if (!is_array($response_data)) { continue; } ?>
                    <tr class="ffed-entry-row">
                        <?php
                        if ($use_specific_fields) {
                            $field_keys_for_row = $display_fields;
                        } elseif (is_array($first_response_data)) {
                            $field_keys_for_row = array_keys($first_response_data);
                        } else {
                            $field_keys_for_row = array();
                        }

                        $column_count = 0;
                        foreach ($field_keys_for_row as $field_key) {
                            $value = isset($response_data[$field_key]) ? $response_data[$field_key] : '';

                            // MODIFICATION: Handle the 'names' field correctly
                            if ($field_key === 'names' && is_array($value)) {
                                $value = $value['first_name'] . ' ' . $value['last_name']; // Concatenate first and last names
                            }

                            if (is_array($value) && $field_key !== 'names') { // Prevent imploding the already processed 'names' array
                                $value = implode(', ', $value);
                            }
                            echo '<td data-ffed-column="' . esc_attr($field_key) . '">' . esc_html( $value ) . '</td>';
                            $column_count++;
                        }

                        if ($show_date) {
                            echo '<td>' . esc_html( date_i18n( get_option('date_format') . ' ' . get_option('time_format'), strtotime($row->created_at) ) ) . '</td>';
                        }
                        ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    $output = ob_get_clean();

    // --- 8. Display the Entry Count ---
    if ($show_count) {
        $output .= '<p class="ffed-total-entries">' . esc_html($count_text) . ' ' . esc_html(count($results)) . '</p>';
    }

    // --- 9. JavaScript for Live Search ---
    if ($enable_search && $name_column_index !== false) {
        $output .= '<script type="text/javascript">
            document.addEventListener("DOMContentLoaded", function() {
                const searchInput = document.getElementById("ffed-search-input");
                const tableRows = document.querySelectorAll(".ffed-entries-table tbody tr.ffed-entry-row");
                const nameColumnIndex = ' . esc_js($name_column_index) . ';

                searchInput.addEventListener("keyup", function() {
                    const searchTerm = searchInput.value.toLowerCase();

                    tableRows.forEach(row => {
                        const cells = row.querySelectorAll("td");
                        if (cells.length > nameColumnIndex) {
                            const name = cells[nameColumnIndex].textContent.toLowerCase();
                            if (name.includes(searchTerm)) {
                                row.style.display = "";
                            } else {
                                row.style.display = "none";
                            }
                        }
                    });
                });
            });
        </script>';
    }

    return $output;
}

/**
 * Basic CSS for the table.
 */
function ffed_add_basic_styles() {
    if (!is_admin()) {
        echo '<style>
        .ffed-table-wrapper { overflow-x: auto; margin-bottom: 1.5em; }
        .ffed-entries-table { width: 100%; border-collapse: collapse; border: 1px solid #ddd; }
        .ffed-entries-table th, .ffed-entries-table td { padding: 8px 12px; border: 1px solid #ddd; text-align: left; word-wrap: break-word; } /* Added word-wrap */
        .ffed-entries-table th { background-color: #f2f2f2; font-weight: bold; }
        .ffed-entries-table tbody tr:nth-child(odd) { background-color: #f9f9f9; }
        .ffed-entries-table tbody tr:hover { background-color: #f1f1f1; }
        .ffed-search-container { margin-bottom: 10px; }
        .ffed-search-container label { display: inline-block; margin-right: 5px; font-weight: bold; }
        .ffed-search-container input[type="text"] { padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        .ffed-total-entries { margin-top: 10px; font-style: italic; }
        </style>';
    }
}
add_action( 'wp_head', 'ffed_add_basic_styles' );


/**
 * Register permanent strings for Polylang for translation
 */
add_action('init', function() {
    pll_register_string('fluent-forms-entry-display', 'Total Entries:');
    pll_register_string('fluent-forms-entry-display', 'Search Names:');
    pll_register_string('fluent-forms-entry-display', 'Enter name...');
});
