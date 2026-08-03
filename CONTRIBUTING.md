# Contributing

Bug reports, feature requests and pull requests are all welcome. For anything
larger than a fix, open an issue first so you do not build something that is
already half-built somewhere else.

## Licensing of contributions

By contributing, you agree that your contribution is licensed under the same
terms as the project (PolyForm Noncommercial 1.0.0 with the Convention and
Education Rider, see [LICENSE](LICENSE)), and that you have the right to submit
it. If your employer owns your work, get their sign-off before you open the pull
request.

Because the project is source-available rather than open source, code taken from
GPL, AGPL or similarly licensed projects cannot be merged. MIT, BSD, ISC and
Apache-2.0 code is fine as long as you keep its copyright notice.

## Before you open a pull request

```bash
./vendor/bin/pint          # PHP formatting, matches CI
php artisan test           # the suite
npm run build              # catches template and import errors
```

Keep the diff to one subject. A pull request that fixes a bug and reformats
three unrelated files is two pull requests.

## Style

Match the file you are editing: its naming, its comment density, its idiom. The
codebase comments the *why*, not the *what*, and only where a reader would
otherwise wonder. Do not add a comment on every line.

Admin screens are declared server-side with the `App\Support\Manage` toolkit
(`Table`, `Column`, `Filter`, `Action`, `Status`, `Toast`) and rendered by the
shared components in `resources/js/Components/Manage`. Adding a screen means
declaring it, not writing a new page from scratch.

Nothing convention-specific belongs in the code. Names, copy, links, logo and
accent colour resolve through `App\Services\BrandingService`, backed by the
`branding_settings` table with neutral fallbacks in `config/branding.php`. Never
add a convention's name, domain or logo as a literal or as a config default.

## Running it locally

See the README for setup, and [docs/dev-stack.md](docs/dev-stack.md) for the two
ways to get video on a laptop.
