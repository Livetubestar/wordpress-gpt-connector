<?php
/**
 * Plugin Name: LivetubeSTAR Site Control
 * Plugin URI:  https://github.com/Livetubestar/wordpress-gpt-connector
 * Description: ChatGPT Custom GPTからWordPressへ記事を投稿・更新するためのREST APIエンドポイントを提供します。
 * Version:     1.0.0
 * Author:      LivetubeSTAR
 * License:     MIT
 *
 * セットアップ:
 *   wp-config.php に以下を追加してください:
 *   define('LTS_API_KEY', 'your-secret-api-key-here');
 *
 * エンドポイント:
 *   POST /wp-json/lts/v1/create-post  - 記事を新規作成
 *   POST /wp-json/lts/v1/update-post  - 記事を更新
 *   GET  /wp-json/lts/v1/categories   - カテゴリー一覧を取得
 *   GET  /wp-json/lts/v1/tags         - タグ一覧を取得
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// =========================================================
// REST API ルート登録
// =========================================================

add_action( 'rest_api_init', function () {

    register_rest_route( 'lts/v1', '/create-post', [
        'methods'             => 'POST',
        'callback'            => 'lts_create_post',
        'permission_callback' => 'lts_verify_api_key',
    ] );

    register_rest_route( 'lts/v1', '/update-post', [
        'methods'             => 'POST',
        'callback'            => 'lts_update_post',
        'permission_callback' => 'lts_verify_api_key',
    ] );

    register_rest_route( 'lts/v1', '/categories', [
        'methods'             => 'GET',
        'callback'            => 'lts_get_categories',
        'permission_callback' => 'lts_verify_api_key',
    ] );

    register_rest_route( 'lts/v1', '/tags', [
        'methods'             => 'GET',
        'callback'            => 'lts_get_tags',
        'permission_callback' => 'lts_verify_api_key',
    ] );

} );

// =========================================================
// APIキー認証
// =========================================================

function lts_verify_api_key( WP_REST_Request $request ) {
    $api_key = defined( 'LTS_API_KEY' ) ? LTS_API_KEY : '';

    if ( empty( $api_key ) ) {
        return new WP_Error( 'lts_config_error', 'APIキーが設定されていません。wp-config.php を確認してください。', [ 'status' => 500 ] );
    }

    $provided_key = $request->get_header( 'X-API-Key' );

    if ( empty( $provided_key ) || ! hash_equals( $api_key, $provided_key ) ) {
        return new WP_Error( 'lts_unauthorized', 'APIキーが無効です。', [ 'status' => 401 ] );
    }

    return true;
}

// =========================================================
// 記事作成
// =========================================================

function lts_create_post( WP_REST_Request $request ) {
    $params = $request->get_json_params();

    // --- 必須パラメータの確認 ---
    if ( empty( $params['title'] ) || empty( $params['content'] ) ) {
        return new WP_Error( 'lts_bad_request', 'title と content は必須です。', [ 'status' => 400 ] );
    }

    // --- 投稿データの組み立て ---
    $post_data = [
        'post_title'   => sanitize_text_field( $params['title'] ),
        'post_content' => wp_kses_post( $params['content'] ),
        'post_status'  => isset( $params['status'] ) ? sanitize_key( $params['status'] ) : 'draft',
        'post_type'    => 'post',
    ];

    if ( ! empty( $params['excerpt'] ) ) {
        $post_data['post_excerpt'] = sanitize_text_field( $params['excerpt'] );
    }

    if ( ! empty( $params['slug'] ) ) {
        $post_data['post_name'] = sanitize_title( $params['slug'] );
    }

    if ( ! empty( $params['date'] ) ) {
        $post_data['post_date'] = sanitize_text_field( $params['date'] );
    }

    // --- カテゴリーの処理 ---
    $category_ids = [];

    if ( ! empty( $params['category_ids'] ) && is_array( $params['category_ids'] ) ) {
        $category_ids = array_map( 'intval', $params['category_ids'] );
    }

    if ( ! empty( $params['category_names'] ) && is_array( $params['category_names'] ) ) {
        foreach ( $params['category_names'] as $name ) {
            $term = get_term_by( 'name', $name, 'category' );
            if ( $term ) {
                $category_ids[] = $term->term_id;
            } else {
                $new_term = wp_insert_term( $name, 'category' );
                if ( ! is_wp_error( $new_term ) ) {
                    $category_ids[] = $new_term['term_id'];
                }
            }
        }
    }

    if ( ! empty( $category_ids ) ) {
        $post_data['post_category'] = array_unique( $category_ids );
    }

    // --- 記事の作成 ---
    $post_id = wp_insert_post( $post_data, true );

    if ( is_wp_error( $post_id ) ) {
        return new WP_Error( 'lts_insert_error', $post_id->get_error_message(), [ 'status' => 500 ] );
    }

    // --- タグの処理 ---
    $tag_ids = [];

    if ( ! empty( $params['tag_ids'] ) && is_array( $params['tag_ids'] ) ) {
        $tag_ids = array_map( 'intval', $params['tag_ids'] );
    }

    if ( ! empty( $params['tag_names'] ) && is_array( $params['tag_names'] ) ) {
        foreach ( $params['tag_names'] as $name ) {
            $term = get_term_by( 'name', $name, 'post_tag' );
            if ( $term ) {
                $tag_ids[] = $term->term_id;
            } else {
                $new_term = wp_insert_term( $name, 'post_tag' );
                if ( ! is_wp_error( $new_term ) ) {
                    $tag_ids[] = $new_term['term_id'];
                }
            }
        }
    }

    if ( ! empty( $tag_ids ) ) {
        wp_set_post_tags( $post_id, $tag_ids );
    }

    // --- メタディスクリプションの設定 ---
    // ※ SEOプラグイン（Yoast SEO等）を使用している場合は適切なメタキーに変更してください
    if ( ! empty( $params['meta_description'] ) ) {
        // Yoast SEO の場合: _yoast_wpseo_metadesc
        // All in One SEO の場合: _aioseo_description
        // 以下はカスタムフィールドとして保存（必要に応じて変更）
        update_post_meta( $post_id, '_lts_meta_description', sanitize_text_field( $params['meta_description'] ) );
    }

    return rest_ensure_response( [
        'success' => true,
        'post_id' => $post_id,
        'url'     => get_permalink( $post_id ),
        'status'  => $post_data['post_status'],
        'message' => '記事が作成されました。',
    ] );
}

// =========================================================
// 記事更新
// =========================================================

function lts_update_post( WP_REST_Request $request ) {
    $params = $request->get_json_params();

    if ( empty( $params['post_id'] ) ) {
        return new WP_Error( 'lts_bad_request', 'post_id は必須です。', [ 'status' => 400 ] );
    }

    $post_id = intval( $params['post_id'] );
    $post    = get_post( $post_id );

    if ( ! $post ) {
        return new WP_Error( 'lts_not_found', '指定された記事が見つかりません。', [ 'status' => 404 ] );
    }

    $post_data = [ 'ID' => $post_id ];

    if ( ! empty( $params['title'] ) ) {
        $post_data['post_title'] = sanitize_text_field( $params['title'] );
    }

    if ( ! empty( $params['content'] ) ) {
        $post_data['post_content'] = wp_kses_post( $params['content'] );
    }

    if ( ! empty( $params['excerpt'] ) ) {
        $post_data['post_excerpt'] = sanitize_text_field( $params['excerpt'] );
    }

    if ( ! empty( $params['slug'] ) ) {
        $post_data['post_name'] = sanitize_title( $params['slug'] );
    }

    if ( ! empty( $params['status'] ) ) {
        $post_data['post_status'] = sanitize_key( $params['status'] );
    }

    if ( ! empty( $params['date'] ) ) {
        $post_data['post_date'] = sanitize_text_field( $params['date'] );
    }

    // --- カテゴリーの処理 ---
    $category_ids = [];

    if ( ! empty( $params['category_ids'] ) && is_array( $params['category_ids'] ) ) {
        $category_ids = array_map( 'intval', $params['category_ids'] );
    }

    if ( ! empty( $params['category_names'] ) && is_array( $params['category_names'] ) ) {
        foreach ( $params['category_names'] as $name ) {
            $term = get_term_by( 'name', $name, 'category' );
            if ( $term ) {
                $category_ids[] = $term->term_id;
            } else {
                $new_term = wp_insert_term( $name, 'category' );
                if ( ! is_wp_error( $new_term ) ) {
                    $category_ids[] = $new_term['term_id'];
                }
            }
        }
    }

    if ( ! empty( $category_ids ) ) {
        $post_data['post_category'] = array_unique( $category_ids );
    }

    // --- 記事の更新 ---
    $result = wp_update_post( $post_data, true );

    if ( is_wp_error( $result ) ) {
        return new WP_Error( 'lts_update_error', $result->get_error_message(), [ 'status' => 500 ] );
    }

    // --- タグの処理 ---
    $tag_ids = [];

    if ( ! empty( $params['tag_ids'] ) && is_array( $params['tag_ids'] ) ) {
        $tag_ids = array_map( 'intval', $params['tag_ids'] );
    }

    if ( ! empty( $params['tag_names'] ) && is_array( $params['tag_names'] ) ) {
        foreach ( $params['tag_names'] as $name ) {
            $term = get_term_by( 'name', $name, 'post_tag' );
            if ( $term ) {
                $tag_ids[] = $term->term_id;
            } else {
                $new_term = wp_insert_term( $name, 'post_tag' );
                if ( ! is_wp_error( $new_term ) ) {
                    $tag_ids[] = $new_term['term_id'];
                }
            }
        }
    }

    if ( ! empty( $tag_ids ) ) {
        wp_set_post_tags( $post_id, $tag_ids );
    }

    if ( ! empty( $params['meta_description'] ) ) {
        update_post_meta( $post_id, '_lts_meta_description', sanitize_text_field( $params['meta_description'] ) );
    }

    return rest_ensure_response( [
        'success' => true,
        'post_id' => $post_id,
        'url'     => get_permalink( $post_id ),
        'message' => '記事が更新されました。',
    ] );
}

// =========================================================
// カテゴリー一覧取得
// =========================================================

function lts_get_categories( WP_REST_Request $request ) {
    $categories = get_categories( [
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ] );

    $result = [];
    foreach ( $categories as $cat ) {
        $result[] = [
            'id'    => $cat->term_id,
            'name'  => $cat->name,
            'slug'  => $cat->slug,
            'count' => $cat->count,
        ];
    }

    return rest_ensure_response( $result );
}

// =========================================================
// タグ一覧取得
// =========================================================

function lts_get_tags( WP_REST_Request $request ) {
    $tags = get_tags( [
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ] );

    $result = [];
    foreach ( $tags as $tag ) {
        $result[] = [
            'id'    => $tag->term_id,
            'name'  => $tag->name,
            'slug'  => $tag->slug,
            'count' => $tag->count,
        ];
    }

    return rest_ensure_response( $result );
}
