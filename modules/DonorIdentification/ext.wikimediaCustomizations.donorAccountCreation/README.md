This experiment shows a popup to donors inviting them to create an account.

For development purposes you can opt into the experiment with the following code:

```
mw.loader.using('mediawiki.cookie')

const created = (
    new Date( Date.now() - ( 60 * 60 * 25 * 30 * 1000 ) )
) / 1000;
$.cookie( 'centralnotice_hide_fundraising', JSON.stringify({"v":1,created,"reason":"donate"} ), { path: '/' } );
```

Test using the url param `?campaign=reader-donor-account` or `?mpo=donor-status-consent:treatment` (if using cookie)
