# chamilo-wordpress
Chamilo integration plugin for Wordpress

## Install

To install this plugin from source, do the following inside the wp-content/plugins/ directory:
```
git clone https://github.com/chamilo/chamilo-wordpress chamilo
```

Then go to your Wordpress plugins panel and go to the "Reading" settings page.
Locate the "Chamilo connectivity" section and add your Chamilo URL (like "http://my.chamilo.net/"), the admin username ("admin" or any other username you might have set) and the security key (find it in your app/config/configuration.php by looking for "security_key").

### Configuring the courses list widget

Go to the widgets section of your Wordpress configuration panel and locate the "Chamilo Courses list" widget. Place it somewhere useful (drag & drop).

That's it! Now you can see the list of courses from your portal (depending on where you placed the widget).

## Roadmap

In the future, we will add:
- a personal list of courses (not all the public ones but just yours)
- a way to create accounts in Chamilo when they're created in Wordpress
- a way to do Single Sign On (avoid double authentication in Wordpress and Chamilo)
