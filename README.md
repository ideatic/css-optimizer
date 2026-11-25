# css-optimizer

Optimize, minify and add vendor prefixes to your CSS, without breaking it.

`css-optimizer` only changes what it can prove is equivalent. Anything it does not know (new syntax, unknown
at-rules, custom properties, values with `var()`...) is kept exactly as it was, so it is safe to use with current
and future CSS.

Requires PHP 8.5.

## Install

```bash
composer require ideatic/css-optimizer
```

## Usage

```php
$optimizer = new css_optimizer([
    'compress' => true,      // Remove whitespace and unneeded symbols
    'prefixes' => 'webkit',  // 'all', '' (none), or a list: 'webkit,mozilla,opera,msie'
]);

echo $optimizer->process('a { color: white; margin: 0px 0px 0px 0px; }');
// a{color:#fff;margin:0}
```

`process()` accepts a CSS string (and returns a string) or a parsed `css_group` (and changes it in place):

```php
$document = new css_parser()->parse($css);
$optimizer->process($document);
echo $document->render(compressed: true);
```

### Settings

| Setting           | Default | What it does                                                                         |
|-------------------|---------|--------------------------------------------------------------------------------------|
| `compress`        | `true`  | Remove whitespace and the last `;` of each block                                     |
| `remove_comments` | `true`  | Remove comments. Important comments (`/*! license */`) are kept                      |
| `optimize`        | `true`  | Shorten values (see below)                                                           |
| `remove_empty`    | `true`  | Remove empty rules, and empty `@media`, `@supports`, `@container`...                 |
| `extra_optimize`  | `false` | Merge adjacent rules with the same declarations: `.a{x}.b{x}` → `.a,.b{x}`           |
| `remove_ie_hacks` | `false` | Remove old Internet Explorer hacks: `_prop`, `*prop`, `progid:` filters, `\9`...     |
| `prefixes`        | `'all'` | Vendor prefixes to add                                                               |

An unknown setting throws an `InvalidArgumentException`. Boolean settings also accept strings such as `'0'` or
`'false'`, because they often come from config files or the command line.

### What `optimize` changes

- Colors in color properties: `white` → `#fff`, `#FFFFFF` → `#fff`, `rgb(255, 0, 0)` → `#f00`.
- Numbers: `0.5` → `.5`, and zero lengths: `0px` → `0` (not inside `calc()` or other functions, not in `flex`).
- Repeated values: `margin: 1px 2px 1px 2px` → `margin: 1px 2px`, `gap: 1rem 1rem` → `gap: 1rem`.
- `margin-top`, `-right`, `-bottom` and `-left` → `margin` (also `padding` and `border-width`), only when each one
  appears once, with a simple value and without `!important`.
- `font-weight: bold` → `700`, `background-position: top left` → `0 0`.
- Whitespace after commas, before `!important` and around selector combinators.

### What it never changes

- Custom properties (`--name: value`): name case and value are kept as they are.
- Strings, `url()` and the content of `@font-face`, `@property`, `@counter-style`, `@page` and unknown at-rules.
- Values that are not well formed, and values with `var()`, `env()` or `attr()` in shorthand merges.
- Shorthands whose merge can change the result (`font`, `background`, `border-radius`...).
- The order of rules: `extra_optimize` only merges rules that are next to each other, and never selectors with
  vendor or new pseudo-classes (one unsupported selector would drop the whole merged rule).

### Vendor prefixes

Prefixed declarations are added **before** the standard one, so browsers that know the standard syntax use it:

```css
a{-webkit-transition:color 1s;transition:color 1s}
```

Gradients are converted to the old prefixed syntax only when the conversion is exact (`to bottom` → `top`,
`135deg` → `-45deg`). Otherwise no prefixed copy is added. `@keyframes` get a prefixed copy for each vendor.

## Command line

```bash
vendor/bin/css_optimizer.php [options] input.css [output.css]
```

Run it with `-h` to see all the options (`-c=false` to keep the format, `-v=none` for no prefixes, `-s` to read
from STDIN, `-u` to remove unused selectors).

## css_cleaner

`css_cleaner` removes the selectors that your project does not use.

It reads **all** the project files (templates, PHP, JavaScript...), takes every name found in them, and removes
the selectors whose classes, ids or tags are not found. It is like using a bazooka to kill a fly, but it works.

```php
$cleaner = new css_cleaner();
$cleaner->project_files = ['templates/', 'js/'];
$cleaner->method = css_cleaner::METHOD_SAFE; // or METHOD_BEST_CLEAN: only names in strings and HTML tags
$cleaner->report_path = 'selector-usage.txt'; // optional
$cleaner->clean($document);
```

Rules inside `@keyframes` (`from`, `50%`...) and other non-style at-rules are never removed.

## Development

```bash
composer install
```

```bash
composer test
```

```bash
composer phpstan
```

The tests are in `tests/`. `examples/test.php` and `examples/cleaner.php` are small web pages to try the
optimizer and the cleaner by hand.
