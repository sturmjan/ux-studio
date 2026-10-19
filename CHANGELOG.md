# Changelog

## 0.4.1

- Security - Access Control: with *Restrict REST API to logged-in users* on,
  `/complianz/v1/` is now public by default, so the Complianz cookie banner
  loads and consent is stored without a workaround. The built-in list is the
  initial value of the `uxstudio_rest_public_routes` filter; the match is still
  a strict prefix on the resolved route. Core routes (`/wp/v2/users`…) stay
  closed for anonymous visitors.
