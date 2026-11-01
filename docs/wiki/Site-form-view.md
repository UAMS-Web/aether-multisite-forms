The add-on hands templates and the submit route a read-only view of a form for the page's site, a `UAMSWeb\Aether\Forms\Multisite\Forms\SiteForm`. It carries the stored form's values and refuses to be saved or deleted. A submission made on the front end is built from that view, so `$submission->form()` in an event listener returns it.

| Method | Returns |
| --- | --- |
| `site()` | The handle of the site the view was resolved for. |
| `base()` | The stored form, in its own class. |
| `title()`, `email()`, `store()` | The site's values, with its [overrides](Per-site-title-email-and-store.md) applied. |

The view carries no `site_overrides` rows: those stay on `base()`, so other sites' recipients never reach a template's `form_config`.

## Forms stored by a driver

The view is always a subclass of Statamic's own `Statamic\Forms\Form`, whatever class your forms are stored as. Where a driver stores forms in its own class, such as `statamic/eloquent-driver`'s `Statamic\Eloquent\Forms\Form`, that class's methods are not on the view: `model()` throws, and an `instanceof` check against the driver's class is false. Reach them through `base()`:

```php
use Statamic\Events\FormSubmitted;
use UAMSWeb\Aether\Forms\Multisite\Forms\SiteForm;

Event::listen(function (FormSubmitted $event) {
    $form = $event->submission->form();

    if ($form instanceof SiteForm) {
        $form = $form->base();
    }

    $row = $form->model(); // the driver's own method
});
```
