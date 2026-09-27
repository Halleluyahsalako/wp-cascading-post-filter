# Cascading Post Filter

An AJAX archive filter for WordPress: keyword search, year, and a parent taxonomy select that reveals a child select when the parent has children. Works with any post type and any hierarchical taxonomy.

## Why it owns its own query

The usual way to build this is to drop a filter plugin on top of a page builder's post widget. Neither one fully owns the result set, so the filter reaches into the builder's markup to swap results, and the whole arrangement breaks the next time the builder changes its internals or post IDs shift.

This does the whole job: the shortcode renders the controls, a single AJAX handler runs `WP_Query`, and the JavaScript renders the cards. There is nothing between the query and the output to go stale.

## Usage

```
[post_filter]
```

Defaults to posts filtered by category. Any post type and hierarchical taxonomy works:

```
[post_filter post_type="publication" taxonomy="topic" per_page="24" columns="4"]
```

| Attribute | Default | Notes |
|---|---|---|
| `post_type` | `post` | Any registered public post type |
| `taxonomy` | `category` | Any hierarchical taxonomy on that post type |
| `per_page` | `12` | Capped at 50 server side |
| `columns` | `3` | Drops to 2 then 1 on narrow screens |
| `search` | `yes` | Set to `no` to hide the search box |
| `years` | `yes` | Set to `no` to hide the year select |

## The cascade

Pick a parent term and a second select appears with its children, fetched on demand. Pick nothing and it stays hidden, so a flat taxonomy never shows an empty control.

Selecting a child narrows to that child alone. Selecting only a parent includes its children, which is what people expect from a category filter and what `include_children` does for you.

## Details worth knowing

**Years come from the content.** The dropdown is built from years that actually have published posts, cached for a day and cleared on save. The version this was extracted from hardcoded a range ending at 2020, which quietly stopped being correct as soon as the site had older or newer content.

**Requests are aborted when superseded.** A fast typist can outrun the network, and responses do not necessarily return in the order they were sent. Each new request aborts the one in flight.

**Search is debounced by 400ms**, so typing does not fire a request per keystroke.

**Cards are built with `createElement`.** Titles and excerpts are set with `textContent`, so a post titled with markup cannot inject it.

**No jQuery.** Plain `fetch` and `AbortController`.

**Assets load only where the shortcode runs.** Registered on `wp_enqueue_scripts`, enqueued inside the shortcode.

**Scoped CSS, no `!important`.** Everything sits under `.cpfilter` and is driven by custom properties:

```css
.cpfilter {
    --cpfilter-columns: 4;
    --cpfilter-radius: 0;
    --cpfilter-accent: #1c5d99;
}
```

## Filters

| Filter | Purpose |
|---|---|
| `cpfilter_query_args` | Modify the `WP_Query` arguments behind the results |

## Requirements

WordPress 6.0+, PHP 7.4+. No dependencies.

## Status

Extracted from production code and generalised. The original was hardwired to one site's blog and categories, with a hardcoded year range and unescaped term output in the markup. Both are fixed here.

Issues and pull requests welcome.

## Licence

GPL-2.0-or-later.
