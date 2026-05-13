<?php
declare(strict_types=1);

namespace WPTransformed\Modules\DisableComponents;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPTransformed\Modules\Module_Base;

/**
 * Disable REST Fields — Remove sensitive fields from the REST API user endpoint.
 *
 * Strips selected fields (email, registered_date, etc.) from user responses
 * and optionally removes the /wp/v2/users endpoint entirely.
 *
 * @package WPTransformed
 */
class Disable_Rest_Fields extends Module_Base {

    /**
     * Cached list of fields to remove (avoids re-reading settings per response).
     *
     * @var string[]
     */
    private array $fields_to_remove = [];

    // ── Identity ──────────────────────────────────────────────

    public function get_id(): string {
        return 'disable-rest-fields';
    }

    public function get_title(): string {
        return __( 'Disable REST Fields', 'wptransformed' );
    }

    public function get_category(): string {
        return 'disable-components';
    }

    public function get_description(): string {
        return __( 'Remove sensitive fields from the WordPress REST API user endpoint and optionally disable it entirely.', 'wptransformed' );
    }

    // ── Settings ──────────────────────────────────────────────

    public function get_default_settings(): array {
        return [
            'remove_fields'         => [ 'email', 'registered_date', 'capabilities', 'extra_capabilities' ],
            'remove_users_endpoint' => false,
        ];
    }

    // ── Lifecycle ─────────────────────────────────────────────

    public function init(): void {
        $settings = $this->get_settings();

        if ( ! empty( $settings['remove_fields'] ) ) {
            $this->fields_to_remove = array_map( 'sanitize_key', (array) $settings['remove_fields'] );
            add_filter( 'rest_prepare_user', [ $this, 'filter_user_fields' ], 10, 3 );
        }

        if ( ! empty( $settings['remove_users_endpoint'] ) ) {
            add_filter( 'rest_endpoints', [ $this, 'remove_users_endpoint' ] );
        }
    }

    /**
     * Remove selected fields from REST API user responses.
     *
     * @param \WP_REST_Response $response The response object.
     * @param \WP_User          $user     The user object.
     * @param \WP_REST_Request  $request  The request object.
     * @return \WP_REST_Response
     */
    public function filter_user_fields( $response, $user, $request ): \WP_REST_Response {
        $data = $response->get_data();

        foreach ( $this->fields_to_remove as $field ) {
            unset( $data[ $field ] );
        }

        $response->set_data( $data );
        return $response;
    }

    /**
     * Remove /wp/v2/users and /wp/v2/users/(?P<id>...) endpoints.
     *
     * @param array $endpoints The registered REST endpoints.
     * @return array
     */
    public function remove_users_endpoint( array $endpoints ): array {
        foreach ( array_keys( $endpoints ) as $route ) {
            if ( preg_match( '#^/wp/v2/users#', $route ) ) {
                unset( $endpoints[ $route ] );
            }
        }
        return $endpoints;
    }

    // ── Settings UI ───────────────────────────────────────────

    public function render_settings(): void {
        $settings      = $this->get_settings();
        $remove_fields = (array) $settings['remove_fields'];

        $available_fields = [
            'email'              => __( 'Email', 'wptransformed' ),
            'registered_date'    => __( 'Registered Date', 'wptransformed' ),
            'capabilities'       => __( 'Capabilities', 'wptransformed' ),
            'extra_capabilities' => __( 'Extra Capabilities', 'wptransformed' ),
            'url'                => __( 'URL', 'wptransformed' ),
            'description'        => __( 'Description', 'wptransformed' ),
        ];
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Fields to Remove', 'wptransformed' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $available_fields as $key => $label ) : ?>
                            <label>
                                <input type="checkbox"
                                       name="wpt_remove_fields[]"
                                       value="<?php echo esc_attr( $key ); ?>"
                                       <?php checked( in_array( $key, $remove_fields, true ) ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </label><br>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="description">
                        <?php esc_html_e( 'Selected fields will be stripped from /wp/v2/users responses.', 'wptransformed' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Disable Users Endpoint', 'wptransformed' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="wpt_remove_users_endpoint"
                               value="1"
                               <?php checked( ! empty( $settings['remove_users_endpoint'] ) ); ?>>
                        <?php esc_html_e( 'Completely remove the /wp/v2/users endpoint', 'wptransformed' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Sanitize Settings ─────────────────────────────────────

    public function sanitize_settings( array $raw ): array {
        $valid_fields = [ 'email', 'registered_date', 'capabilities', 'extra_capabilities', 'url', 'description' ];

        $remove_fields = [];
        if ( ! empty( $raw['wpt_remove_fields'] ) && is_array( $raw['wpt_remove_fields'] ) ) {
            foreach ( $raw['wpt_remove_fields'] as $field ) {
                $field = sanitize_key( $field );
                if ( in_array( $field, $valid_fields, true ) ) {
                    $remove_fields[] = $field;
                }
            }
        }

        return [
            'remove_fields'         => $remove_fields,
            'remove_users_endpoint' => ! empty( $raw['wpt_remove_users_endpoint'] ),
        ];
    }

    // ── Cleanup ───────────────────────────────────────────────

    public function get_cleanup_tasks(): array {
        return [];
    }
}
