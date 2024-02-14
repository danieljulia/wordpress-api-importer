<?php
/*
Plugin Name: Import from a remote WP API
Description: Import posts from a remote WordPress site using the WP REST API
Version: 0.1
Author: Pimpampum.net
*/


require "config.php";


function call_api() {
    $page_num = isset($_GET['page_num']) ? intval($_GET['page_num']) : 1;
    $url=BASE_URL.'/wp-json/wp/v2/posts';
    $url=$url.'?page='.$page_num;

 
    //get contents of this url 
    $html=wp_remote_get($url);
    $posts = json_decode(wp_remote_retrieve_body($html));
   

    echo "<h1>Importing posts<br>".BASE_URL."<br>page: {$page_num}</h1>";
    echo "<ul class='import-list'>";
    //pagination 
    echo "<div class='pagination'>";
    if ($page_num > 1) {
        echo "<a href='" . admin_url('admin.php?page=api-importer&page_num=' . ($page_num - 1)) . "'>Previous</a>";
    }
    echo " | <a href='" . admin_url('admin.php?page=api-importer&page_num=' . ($page_num + 1)) . "'>Next</a>";
    echo "</div>";

    if (!empty($posts)) {
        foreach ($posts as $post) {

           // print_r($post);
      
      
            //check if the post exists
            $post_id = post_exists($post->title->rendered);
            if ($post_id) {
                //add link to the edit link post 
                print "<li class='imported'>[imported] <a target='wp' href='".get_edit_post_link($post_id)."'>".$post->title->rendered."</a></li>";
              
             
            }else{
                print "<li class='importing'>[importing] ".$post->title->rendered."</li>";
                $post_content = $post->content->rendered;
                $category_ids=termsGet($post->categories,'categories');
                $tag_ids=termsGet($post->tags,'tags');
                
                // Create post object
                $my_post = array(
                    'post_title'    => $post->title->rendered,
                    'post_content'  => $post_content,
                    'post_status'   => 'publish',
                    'post_author'   => 1,
                    'post_category' => $category_ids,
                    'post_date' => $post->date,
                    'post_name' => $post->slug,
                );
                //get the featured image id from json  featured_media
                $featured_image_id = $post->featured_media;
                $featured=get_remote_featured_image($featured_image_id);
                $local_id=get_local_featured_image($featured);
                if ($local_id==0) {
                    //baixa la imatge i crea l'attachment i retorna id
                    $local_id=create_local_featured_image($featured);
                }else{
          
                    
                }
                
                //get an array of all the images inside the content 
                preg_match_all('/<img[^>]+>/i',$post->content->rendered, $result);
                $images=$result[0];
                //foreach image in the content create an attachment if it's not already created and replace the url with the new one
                foreach ($images as $image) {
                    preg_match_all('/src="([^"]+)"/i',$image, $result);
                    $image_url=$result[1][0];
                    $image_filename = basename($image_url);
                    $local_id=get_local_featured_image(['url'=>$image_url,'filename'=>$image_filename]);
                    if ($local_id==0) {
                        //baixa la imatge i crea l'attachment i retorna id
                        $local_id=create_local_featured_image(['url'=>$image_url,'filename'=>$image_filename]);
                    }else{
                      
                    }
                      //replace the url with the new one
                      $post_content =str_replace($image_url,wp_get_attachment_url($local_id),$post_content );
                
                }
                $my_post['post_content']=$post_content;

               
                // Insert the post into the database
                $post_id = wp_insert_post($my_post);
                if (is_wp_error($post_id)) {
                    echo 'Post insertion error: ' . $post_id->get_error_message();
                } else {
                    // Set the featured image for the post
                    set_post_thumbnail($post_id, $local_id);
                    wp_set_post_tags($post_id, $tag_ids);
                }
         
            }
        }
        echo "</ul>";
   
    } else {
        echo 'No posts found';
    }
}

function get_remote_featured_image($featured_image_id){
    $url=BASE_URL.'/wp-json/wp/v2/media/'.$featured_image_id;
    $html=wp_remote_get($url);
    $image = json_decode(wp_remote_retrieve_body($html)); 
   
    $image_url = $image->guid->rendered;
    $image_filename = basename($image_url);
    return   ['url'=>$image_url,'filename'=>$image_filename];

}

function get_local_featured_image($featured){
    $args = array(
        'post_type' => 'attachment',
        'post_status' => null,
        'name' => $featured['filename']
    );
    $attachments = get_posts($args);
    if ($attachments) {
        foreach ($attachments as $attachment) {
            return $attachment->ID;
        }
    }
    return 0;
}

function create_local_featured_image($featured){
 
    $upload_dir = wp_upload_dir();
    $image_data = file_get_contents($featured['url']);

    if (wp_mkdir_p($upload_dir['path'])) {
        $file = $upload_dir['path'] . '/' . $featured['filename'];
    } else {
        $file = $upload_dir['basedir'] . '/' . $featured['filename'];
    }
    file_put_contents($file, $image_data);
    $wp_filetype = wp_check_filetype($featured['filename'], null);
    $attachment = array(
        'post_mime_type' => $wp_filetype['type'],
        'post_title' => sanitize_file_name($featured['filename']),
        'post_content' => '',
        'post_status' => 'inherit'
    );
    $attach_id = wp_insert_attachment($attachment, $file);
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attach_data = wp_generate_attachment_metadata($attach_id, $file);
    wp_update_attachment_metadata($attach_id, $attach_data);
    return $attach_id;

}



function termsGet($term_ids, $taxonomy){
    $ids=[];
    foreach($term_ids as $term_id){
        $term_name=termNameGet($term_id, $taxonomy);
       
        //check if the term exists in the current WP and get the id, else create and get also the id 
        $id=termGetLocalId($term_name, $taxonomy);
      
        $ids[]=$id;
    }
    return $ids;
}

function taxTranslate($taxonomy){
    if($taxonomy=='categories'){
        return 'category';
    }
    if($taxonomy=='tags'){
        return 'post_tag';
    }
    return $taxonomy;
}

function termGetLocalId($term_name, $taxonomy){
    // Check if there is a term with this name in the current WordPress
    $taxonomy=taxTranslate($taxonomy);
    $term = get_term_by('name', $term_name, $taxonomy);

    // If the term exists, return its ID
    if ($term !== false) {
        return $term->term_id;
    }

    // If the term doesn't exist, create it
    $taxonomy=taxTranslate($taxonomy);
    $result = wp_insert_term($term_name, $taxonomy);

    // If there was an error creating the term, handle it
    if (is_wp_error($result)) {
        print_r($result);
        return null; // Or handle the error in some other way
    }

    // Return the ID of the new term
    return $result['term_id'];
}

$remote_terms = [];

function termNameGet($term_id, $taxonomy){
    global $remote_terms;

    // Check if it's in the cache 
    if(!isset($remote_terms[$taxonomy])){
        $remote_terms[$taxonomy] = [];
    }

    if(isset($remote_terms[$taxonomy][$term_id])){
        return $remote_terms[$taxonomy][$term_id];
    }


    // If not in the cache, fetch the term from the API
    $response = wp_remote_get(BASE_URL.'/wp-json/wp/v2/' . $taxonomy . '/' . $term_id);
    if (is_wp_error($response)) {
        print_r($response);
        return null; // Or handle the error in some other way
    }

    // Decode the response and get the term name
    $term_data = json_decode(wp_remote_retrieve_body($response), true);
    $term_name = $term_data['name'];

    // Store the term name in the cache
    if(!isset($remote_terms[$taxonomy])){
        $remote_terms[$taxonomy] = [];
    }
    $remote_terms[$taxonomy][$term_id] = $term_name;

    return $term_name;
}


function api_importer_menu() {
    add_menu_page(
        'API Importer', // page title
        'API Importer', // menu title
        'manage_options', // capability
        'api-importer', // menu slug
        'call_api' // function to display the page content
    );
}

add_action('admin_menu', 'api_importer_menu');

function add_api_importer_custom_query_var($vars){
    $vars[] = "page_num";
    return $vars;
}

add_filter('query_vars', 'add_api_importer_custom_query_var');

function api_importer_enqueue_admin_styles($hook) {
    // Check if we're on the correct admin page
    if ('toplevel_page_api-importer' !== $hook) {
        return;
    }

    // Register and enqueue the style
    wp_register_style('api_importer_style', plugins_url('style.css', __FILE__));
    wp_enqueue_style('api_importer_style');
}

add_action('admin_enqueue_scripts', 'api_importer_enqueue_admin_styles');