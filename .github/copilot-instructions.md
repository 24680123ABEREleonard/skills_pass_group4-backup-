# Project Notes

- This project uses PHP 8.1+, MySQL, HTML, and CSS without a framework.
- Use PDO prepared statements for database access and escape dynamic output with `escape()`.
- Enforce candidate/employer authorization on the server for all private actions.
- Use CSRF tokens on state-changing forms.
- Never store plaintext passwords or OTP codes, or expose secrets in committed files.
- Keep OTP expiry, attempt limits, and resend throttling enabled.
