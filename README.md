# FAQ

An addon for [Modulento](https://github.com/alex01at/modulento) that adds a
frequently-asked-questions page, managed in the administration. As an
addon, it can be active together with any other addon and with the
installation's one app.

- Each entry is either **general** (shown regardless of what is installed)
  or **assigned to one installed extension** - shown only while that
  extension is enabled. Running freelancer today and shop next year never
  mixes the two sets of questions on the same page.
- **`/faq`**: a public page with every entry the visitor may see, and a
  search box that matches the question or the answer. A link to it is
  added to the site's footer.
- **Administration → FAQ** (permission `faq.items.manage`): a list
  filterable by assignment, and a form per entry with a question and an
  answer (basic formatting allowed) in every enabled language, an
  assignment and a manual order.

## Installing

Clone or symlink this repository into `extensions/faq` of a Modulento
installation, then enable it under Administration → Extensions.

## Running its tests

```
MODULENTO_CORE=/path/to/modulento php tests/run.php
```
