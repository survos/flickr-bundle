# survos/flickr-bundle

A Symfony bundle that wraps flickr library at https://github.com/samwilson/phpflickr

## Installation

```bash
composer require survos/flickr-bundle
```

## Demo App

```bash
symfony new flickr-demo --webapp && cd flickr-demo
composer config extra.symfony.allow-contrib true
composer require survos/flickr-bundle
cat > .env.local <<END
FLICKR_API_KEY=the-key
FLICKR_SECRET=a-secret
END
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
        return $this->render('app.html.twig', [
            'list' => $flickr->photosets()->getList($userId)
        ]);
    }
}
EOF

sed -i "s|Route('/app'|Route('/'|" src/Controller/AppController.php
sed -i "s|'app_app'|'app_homepage'|" src/Controller/AppController.php

cat > templates/app/index.html.twig <<END
{% extends 'base.html.twig' %}
{% block body %}
<div class="container">
<span class="bi bi-clock h1"></span>
<div {{ stimulus_controller('epoch') }}></div>

        <div class="text text-muted">
            created from {{ _self }} on {{ 'now'|date('U') }}
        </div>
    <a class="btn btn-primary">
        <span class="bi bi-download"></span>
        Install as PWA</a>cd 
    </div>
{% endblock %}
END

cat > assets/controllers/epoch_controller.js <<END
import { Controller } from '@hotwired/stimulus';
export default class extends Controller {
connect() {
this.element.textContent = "epoch time is now " +  Date.now();
}
}
END
```

symfony server:start -d
symfony open:local



