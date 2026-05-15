<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Clielo_Ajax {

    public static function init(): void {
        // Envoi de message (utilisateurs connectés uniquement)
        add_action( 'wp_ajax_clielo_send', [ __CLASS__, 'send_message' ] );

        // Récupération des nouveaux messages (polling)
        add_action( 'wp_ajax_clielo_poll', [ __CLASS__, 'poll_messages' ] );

        // Chargement initial des messages
        add_action( 'wp_ajax_clielo_load', [ __CLASS__, 'load_messages' ] );

        // Upload média dans le chat (images, audio)
        add_action( 'wp_ajax_clielo_chat_upload', [ __CLASS__, 'ajax_chat_upload' ] );
    }

    /**
     * Envoie un message.
     */
    public static function send_message(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Vous devez être connecté.', 'clielo' ) ], 403 );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        $message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );

        if ( ! $post_id || empty( $message ) ) {
            wp_send_json_error( [ 'message' => __( 'Données manquantes.', 'clielo' ) ], 400 );
        }

        // Vérifier que le post existe et est du bon CPT
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== Clielo_Admin::get_post_type() ) {
            wp_send_json_error( [ 'message' => __( 'Post invalide.', 'clielo' ) ], 400 );
        }

        $user_id   = get_current_user_id();
        $client_id = absint( $_POST['client_id'] ?? 0 );

        // Déterminer le client_id
        if ( current_user_can( 'manage_options' ) ) {
            // Admin : client_id obligatoire (le client sélectionné)
            if ( ! $client_id ) {
                wp_send_json_error( [ 'message' => __( 'Client non sélectionné.', 'clielo' ) ], 400 );
            }
        } else {
            // Client : forcé à son propre ID
            $client_id = $user_id;
        }

        $inserted = Clielo_DB::insert_message( $post_id, $user_id, $message, $client_id );

        if ( ! $inserted ) {
            wp_send_json_error( [ 'message' => __( 'Erreur lors de l\'envoi.', 'clielo' ) ], 500 );
        }

        // Déclencher la notification
        do_action( 'clielo_message_sent', $post_id, $user_id, $client_id, $inserted );

        $user = wp_get_current_user();

        wp_send_json_success( [
            'id'           => $inserted,
            'post_id'      => $post_id,
            'user_id'      => $user_id,
            'client_id'    => $client_id,
            'display_name' => $user->display_name,
            'message'      => $message,
            'created_at'   => current_time( 'mysql' ),
            'avatar'       => Clielo_Account::get_user_avatar( $user_id, 40 ),
        ] );
    }

    /**
     * Récupère les nouveaux messages depuis le dernier ID connu.
     */
    public static function poll_messages(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [], 403 );
        }

        $post_id   = absint( $_GET['post_id'] ?? 0 );
        $last_id   = absint( $_GET['last_id'] ?? 0 );
        $client_id = absint( $_GET['client_id'] ?? 0 );

        if ( ! $post_id ) {
            wp_send_json_error( [], 400 );
        }

        // Client : forcé à son propre ID
        if ( ! current_user_can( 'manage_options' ) ) {
            $client_id = get_current_user_id();
        }

        $messages = Clielo_DB::get_new_messages( $post_id, $last_id, $client_id );
        $data     = self::format_messages( $messages );

        // Inclure active_order pour garder l'en-tête du chat synchronisé
        $active_order = Clielo_Orders::build_order_response( $post_id );

        wp_send_json_success( [
            'messages'             => $data,
            'active_order'         => $active_order,
            'paid_schedule_ids'    => self::paid_schedule_ids_for_post( $post_id ),
            'expired_schedule_ids' => self::expired_schedule_ids_for_post( $post_id ),
        ] );
    }

    /**
     * Charge les messages initiaux d'un post.
     */
    public static function load_messages(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [], 403 );
        }

        $post_id   = absint( $_GET['post_id'] ?? 0 );
        $client_id = absint( $_GET['client_id'] ?? 0 );

        if ( ! $post_id ) {
            wp_send_json_error( [], 400 );
        }

        // Client : forcé à son propre ID
        if ( ! current_user_can( 'manage_options' ) ) {
            $client_id = get_current_user_id();
        }

        $messages = Clielo_DB::get_messages( $post_id, $client_id );
        $data     = self::format_messages( $messages );

        wp_send_json_success( [
            'messages'             => $data,
            'paid_schedule_ids'    => self::paid_schedule_ids_for_post( $post_id ),
            'expired_schedule_ids' => self::expired_schedule_ids_for_post( $post_id ),
        ] );
    }

    /**
     * Formate les messages pour la réponse JSON.
     * Enrichit les messages système liés à un échéancier avec schedule_id + schedule_paid.
     */
    private static function format_messages( array $messages ): array {
        $current_user_id = get_current_user_id();

        // Collecter tous les schedule_ids présents dans les messages système
        $sched_ids = [];
        foreach ( $messages as $msg ) {
            if ( (int) $msg->user_id === 0 && preg_match( '/\[SF_SCHED:(\d+)\]/', $msg->message, $m ) ) {
                $sched_ids[] = (int) $m[1];
            }
        }
        $paid_sched_ids = class_exists( 'Clielo_Payments' ) && ! empty( $sched_ids )
            ? Clielo_Payments::get_paid_by_ids( $sched_ids )
            : [];

        return array_map( function ( $msg ) use ( $current_user_id, $paid_sched_ids ) {
            $is_system   = (int) $msg->user_id === 0;
            $schedule_id = null;
            $schedule_paid = false;

            if ( $is_system && preg_match( '/\[SF_SCHED:(\d+)\]/', $msg->message, $m ) ) {
                $schedule_id   = (int) $m[1];
                $schedule_paid = in_array( $schedule_id, $paid_sched_ids, true );
            }

            return [
                'id'            => (int) $msg->id,
                'post_id'       => (int) $msg->post_id,
                'user_id'       => (int) $msg->user_id,
                'display_name'  => $is_system ? __( 'Système', 'clielo' ) : ( $msg->display_name ?? '' ),
                'message'       => $msg->message,
                'created_at'    => $msg->created_at,
                'avatar'        => $is_system ? '' : Clielo_Account::get_user_avatar( (int) $msg->user_id, 40 ),
                'is_mine'       => (int) $msg->user_id === $current_user_id,
                'is_system'     => $is_system,
                'schedule_id'   => $schedule_id,
                'schedule_paid' => $schedule_paid,
            ];
        }, $messages );
    }

    /**
     * Retourne les IDs d'échéancier payés pour un post donné.
     */
    private static function paid_schedule_ids_for_post( int $post_id ): array {
        if ( ! class_exists( 'Clielo_Payments' ) ) {
            return [];
        }
        return Clielo_Payments::get_paid_schedule_ids_for_post( $post_id );
    }

    private static function expired_schedule_ids_for_post( int $post_id ): array {
        if ( ! class_exists( 'Clielo_Payments' ) ) {
            return [];
        }
        return Clielo_Payments::get_expired_schedule_ids_for_post( $post_id );
    }

    /**
     * Upload d'un média (image ou audio) dans le chat.
     */
    public static function ajax_chat_upload(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Vous devez être connecté.', 'clielo' ) ], 403 );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        $type    = sanitize_text_field( wp_unslash( $_POST['type'] ?? '' ) );

        if ( ! $post_id || ! in_array( $type, [ 'image', 'audio' ], true ) ) {
            wp_send_json_error( [ 'message' => __( 'Données manquantes.', 'clielo' ) ], 400 );
        }

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== Clielo_Admin::get_post_type() ) {
            wp_send_json_error( [ 'message' => __( 'Post invalide.', 'clielo' ) ], 400 );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES handled via move_uploaded_file
        if ( empty( $_FILES['file'] ) || UPLOAD_ERR_OK !== (int) $_FILES['file']['error'] ) {
            wp_send_json_error( [ 'message' => __( 'Erreur lors de l\'envoi du fichier.', 'clielo' ) ], 400 );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $file      = $_FILES['file'];
        $size      = (int) $file['size'];
        $tmp_name  = $file['tmp_name'];
        $orig_name = sanitize_file_name( basename( wp_unslash( $file['name'] ) ) );

        $size_limit = ( 'image' === $type ) ? 5 * MB_IN_BYTES : 15 * MB_IN_BYTES;
        if ( $size > $size_limit ) {
            wp_send_json_error( [ 'message' => __( 'Fichier trop volumineux.', 'clielo' ) ], 400 );
        }

        $allowed = ( 'image' === $type )
            ? [ 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' ]
            : [ 'webm' => 'audio/webm', 'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav' ];

        $check = wp_check_filetype( $orig_name, $allowed );
        if ( ! $check['type'] ) {
            wp_send_json_error( [ 'message' => __( 'Format de fichier non supporté.', 'clielo' ) ], 400 );
        }

        // Préparer le répertoire d'upload
        $upload   = wp_upload_dir();
        $sub_dir  = 'clielo-chat/' . gmdate( 'Y/m' );
        $chat_dir = $upload['basedir'] . '/' . $sub_dir;
        wp_mkdir_p( $chat_dir );

        $ext      = $check['ext'] ?: ( 'image' === $type ? 'jpg' : 'webm' );
        $filename = wp_unique_filename( $chat_dir, wp_generate_uuid4() . '.' . $ext );
        $filepath = $chat_dir . '/' . $filename;

        if ( ! move_uploaded_file( $tmp_name, $filepath ) ) {
            wp_send_json_error( [ 'message' => __( 'Erreur lors de l\'enregistrement.', 'clielo' ) ], 500 );
        }

        if ( 'image' === $type ) {
            $editor = wp_get_image_editor( $filepath );
            if ( ! is_wp_error( $editor ) ) {
                $editor->resize( 1200, 1200, false );
                $editor->set_quality( 82 );
                $editor->save( $filepath );
            }
        }

        $url = $upload['baseurl'] . '/' . $sub_dir . '/' . $filename;

        $user_id   = get_current_user_id();
        $client_id = absint( $_POST['client_id'] ?? 0 );
        if ( current_user_can( 'manage_options' ) ) {
            if ( ! $client_id ) {
                wp_send_json_error( [ 'message' => __( 'Client non sélectionné.', 'clielo' ) ], 400 );
            }
        } else {
            $client_id = $user_id;
        }

        $token    = ( 'image' === $type ) ? '[CLIELO_IMG:' . $url . ']' : '[CLIELO_AUDIO:' . $url . ']';
        $inserted = Clielo_DB::insert_message( $post_id, $user_id, $token, $client_id );

        if ( ! $inserted ) {
            wp_send_json_error( [ 'message' => __( 'Erreur lors de l\'envoi.', 'clielo' ) ], 500 );
        }

        do_action( 'clielo_message_sent', $post_id, $user_id, $client_id, $inserted );

        $user = wp_get_current_user();
        wp_send_json_success( [
            'id'           => $inserted,
            'post_id'      => $post_id,
            'user_id'      => $user_id,
            'client_id'    => $client_id,
            'display_name' => $user->display_name,
            'message'      => $token,
            'created_at'   => current_time( 'mysql' ),
            'avatar'       => Clielo_Account::get_user_avatar( $user_id, 40 ),
        ] );
    }
}
