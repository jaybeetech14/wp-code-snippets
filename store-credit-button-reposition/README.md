# Store Credit Button Reposition (WooCommerce Cart)

Repositions the "Apply Store Credit" button on the WooCommerce cart page using pure CSS —
no template overrides, no PHP.

## Behavior

- **Desktop (≥769px):** hides the button in its default location inside `.cart_totals` and
  moves it below the coupon section via flexbox ordering on `.cart-collaterals`.
- **Mobile (≤768px):** moves the button to the top of the Cart Totals block, above the
  totals table and checkout button, where thumb reach is best.

## Styling

Includes full-width button styling (padding, border-radius, hover state) in a theme
accent color — adjust `#d85c72` / `#c54d62` to match the active theme.

## Usage

Enqueue in the theme or add via the Customizer's Additional CSS panel:

```php
add_action( 'wp_enqueue_scripts', function() {
    if ( is_cart() ) {
        wp_enqueue_style(
            'jbt-store-credit-reposition',
            get_stylesheet_directory_uri() . '/css/store-credit-button-reposition.css',
            array(),
            '1.0'
        );
    }
} );
```

**File:** `store-credit-button-reposition.css`
