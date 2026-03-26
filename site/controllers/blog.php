<?php
/**
 * Controllers allow you to separate the logic of your templates from your markup.
 * This is especially useful for complex logic, but also in general to keep your templates clean.
 *
 * In this example, we handle tag filtering and paginating blog in the controller,
 * before we pass the currently active tag and the blog to the template.
 *
 * More about controllers:
 * https://getkirby.com/docs/guide/templates/controllers
 */
return function ($page) {

    /**
     * We use the collection helper to fetch the blog collection defined in `/site/collections/blog.php`
     * 
     * More about collections:
     * https://getkirby.com/docs/guide/templates/collections
     */
    $blog = collection('blog');

    $tag = param('tag');
    if (empty($tag) === false) {
        $blog = $blog->filterBy('tags', $tag, ',');
    }

    return [
        'tag'   => $tag,
        'blog' => $blog->paginate(6)
    ];

};
