# survos/flickr-bundle

A Symfony bundle that wraps flickr library at https://github.com/samwilson/phpflickr

In addition to putting the api key and secret in environment variables, there are 2 twig functions that make getting the thumbnail and the photo page easy.  Note that if you embed the thumbnail in a webpage, you are required (by their terms of service) to provide a link to the photo page on Flickr.

```twig
   {% set url = flickrThumbnailUrl(photo) %}
        <figure class="figure">
            <a href="{{ flickrPageUrl(photo) }}" target="_blank">
```

## Installation

```bash
composer require survos/flickr-bundle
```

## Flickr API keys

There are 2 ways to use this bundle. The simplest is if you're only using it to access your own account.  In that case, simply add the api keys to the environment.

The second is if your users can authenticate the flickr account and access their own photos.  In this case, one easy way to store the keys is to add a FlickrUserTrait to the User class.



## Demo App

```bash
symfony new flickr-demo --webapp && cd flickr-demo
composer config extra.symfony.allow-contrib true
cat > .env.local <<END
FLICKR_API_KEY=the-key
FLICKR_SECRET=the-secret
END
composer require survos/flickr-bundle
```


Get an API key and secret at https://www.flickr.com/services/api/keys/ and replace the dummy values in .env.local


```bash
bin/console importmap:require bootstrap
echo "import 'bootstrap/dist/css/bootstrap.min.css'" >> assets/app.js

bin/console make:controller AppController -i
cat <<'EOF' > src/Controller/AppController.php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Survos\FlickrBundle\Services\FlickrService;

class AppController extends AbstractController
{
    #[Route('/', name: 'flickr_list')]
    public function __invoke(FlickrService $flickr): Response
    {
    
        $userId = '26016159@N00';
        $result = $flickr->photosets()->getPhotos(
            72177720317358478,
            $userId,
['media' => 'photos, url_o, tags']
        );

        return $this->render('app.html.twig', [
            'photos' => $result
        ]);
    }
}
EOF

cat > templates/app.html.twig <<END
{% extends 'base.html.twig' %}

{% block body %}
    {% for photo in photos.photo %}
        {% set url = flickrThumbnailUrl(photo) %}
        <figure class="figure">
            <a href="{{ flickrPageUrl(photo) }}" target="_blank">
            <img src="{{ url }}" class="figure-img img-fluid rounded" alt="...">
            </a>
            <figcaption class="figure-caption text-end">{{ photo.title }}</figcaption>
        </figure>

    {% endfor %}
{% endblock %}
END

symfony server:start -d
symfony open:local
```



