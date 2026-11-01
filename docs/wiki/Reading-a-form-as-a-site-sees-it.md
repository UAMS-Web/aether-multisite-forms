Written against v0.6.1.

`Form::find()` returns the form as it is stored, with every site's override rows and no site's values applied. Code that needs a form as one site sees it, with that site's title, email and storage choice, asks the add-on's `FormResolver`.

## Resolve the form for a site

```php
use Statamic\Facades\Form;
use UAMSWeb\Aether\Forms\Multisite\Forms\FormResolver;

$form = app(FormResolver::class)->resolve(Form::find('contact'), 'es');

if ($form === null) {
    // The form is not assigned to the Spanish site.
    return;
}

$form->title(); // 'Contacto'
$form->store(); // false
$form->email(); // the Spanish row's email list
$form->site();  // 'es'
```

The site can be a handle or a `Statamic\Sites\Site`, such as `Site::current()` for the site of the current request.

With `contact` assigned to English and Spanish, and a Spanish row setting the title to `Contacto`, `store` to `false` and its own recipient, the Spanish view returns those values, and the English view returns the form's own: `Contact`, `true`, and the form's email list.

## When it returns null

`resolve()` returns `null` for a form that is not assigned to the site. A form with no sites resolves for every site.

## Changing the form itself

The resolved form is read-only. Saving it throws `UAMSWeb\Aether\Forms\Multisite\Exceptions\ReadOnlyFormException`, so one site's values never reach the form file:

```text
The [contact] form resolved for the [es] site is read-only and cannot be saved. Resolve nothing and use Form::find('contact') to change the form itself.
```

To change the form, use `Form::find()`, or `base()` on the resolved form, which returns the stored form in its own class.

## In a submission listener

A submission made on the front end already carries the form resolved for its site, so `$event->submission->form()` in a `FormSubmitted` listener returns the view, with no call to the resolver needed.

## Reference

- [Site form view](Site-form-view.md): every method of the view, and forms stored by a driver.
- [Per-site title, email and store](Per-site-title-email-and-store.md): how a site's row is read.
