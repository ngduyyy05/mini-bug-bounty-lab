# Methodology

This lab methodology is based on OWASP Web Security Testing Guide concepts and the OWASP API Security Top 10.

## 1. Scope Confirmation

- Identify target hosts and ports.
- Confirm that only local lab services are tested.
- Define user roles: user, staff and admin.
- Confirm in-scope features: authentication, profile, orders, upload, feedback, checkout and admin APIs.

## 2. Reconnaissance

- Review Swagger/OpenAPI documentation at `/docs`.
- Enumerate endpoints, methods, request bodies and response codes.
- Identify role-protected routes and object identifiers.
- Create test accounts for each role.

## 3. Authentication Testing

- Test login behavior with valid and invalid credentials.
- Review JWT header and claims.
- Check token expiration and signature verification.
- Test weak signing secrets with a small demonstration wordlist.
- Test rate limiting on repeated failed login attempts.

## 4. Authorization Testing

- Test horizontal access control by switching object IDs between users.
- Test vertical access control by calling staff/admin APIs as a normal user.
- Verify authorization is enforced server-side, not only in UI logic.

## 5. Injection Testing

- Identify search and filter parameters.
- Submit SQL metacharacters and boolean conditions.
- Confirm whether queries are parameterized in the fixed app.

## 6. Client-Side Injection Testing

- Submit HTML and JavaScript payloads in feedback.
- Review where stored data is rendered.
- Confirm whether output encoding prevents script execution.

## 7. File Upload Testing

- Attempt uploads with unexpected extensions and MIME types.
- Check filename handling and path safety.
- Verify size limits and randomized server-side filenames.

## 8. Business Logic Testing

- Compare product prices returned or stored server-side.
- Manipulate checkout amount and quantity.
- Confirm the server calculates final price independently of client input.

## 9. Security Configuration Testing

- Send requests with hostile `Origin` headers.
- Review CORS response headers.
- Trigger controlled errors and verify that internal stack traces are not exposed.

## 10. Reporting and Retest

- Document each issue with steps to reproduce, impact and recommendation.
- Map findings to OWASP categories.
- Retest against `app-fixed` and record the result.
