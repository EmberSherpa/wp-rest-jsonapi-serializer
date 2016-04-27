# JSONAPI Serializer for WP REST API v2 & Pods - WordPress Plugin

This is a plugin for WordPress that'll make your site's WP REST API generate JSONAPI payload.
This plugin was created to make it easier to use Ember.js and Ember Data as an API client for WordPress.

**Warning: Pods are probably required at this point**

## Features

- Read posts, custom post types, tags and categories via REST API
- Supports Pods custom post types & custom fields
- Supports Pods image fields
- Supports featured image
- Makes API consistent to eliminate awkwardness around tags
- Each response includes all related data for a payload

## Not Supported

- Writing is not supported yet
- Error reporting needs work (primarily converting WP Errors to JSONAPI errors)
- Media attachments are not being included other than featured image
- No consideration for comments (at the moment)

## Ember Data setup

1. Configure Adapter

Create `adapters/application.js` and specify your WordPress' host.

```
import DS from 'ember-data';

export default DS.JSONAPIAdapter.extend({
      host: '<your_wp_host>',
      namespace: 'wp-json/wp/v2'
});
```

2. Create models

Post, Tag, Category, Author, Taxonomy

// TODO: create an Ember Addon to provide these defaults

## Using Slugs

Configure your route accept a slug:


```js
// router.js

import Ember from 'ember';
import config from './config/environment';

const Router = Ember.Router.extend({
  location: config.locationType
});

Router.map(function() {
    this.route('post', { path: 'posts/:slug' };
});

export default Router;
```

```js
// routes/posts.js

import Ember from 'ember';

export default Ember.Route.extend({

  model({slug}) {
    return this.store.queryRecord('post', {
      filter: { name: slug }
    });
  }

});

```

## CORS

You'll need to let WordPress accept API requests from `localhost:4200`.
We're allowing API calls from all hosts by adding the following code via theme's `functions.php`.
You may choose to do the same at your discression.

```php
<?php
add_filter('http_origin', 'http_origin_set_origin_to_all');
function http_origin_set_origin_to_all() {
        return $_SERVER[ 'HTTP_ORIGIN' ];
}
```