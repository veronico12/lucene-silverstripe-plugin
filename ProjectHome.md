# Overview #

This plugin for the SilverStripe framework allows you to harness the power of
the Lucene search engine on your site.

Using a variety of tools, you can also search PDF, Word, Excel, Powerpoint and
plain text files.

It is easy to set up and use.

This plugin uses Zend\_Search\_Lucene from Zend, StandardAnalyzer by Kenny
Katzgrau, and pdf-to-text by Joeri Stegeman for PDF scanning.

Zend\_Search\_Lucene is a PHP port of the Apache project's Lucene search engine.

This extension is inspired by the wpSearch plugin for WordPress.
http://codefury.net/projects/wpSearch/

## Maintainer Contact ##

Darren Inwood
<darren (dot) inwood (at) chrometoaster (dot) com>


## Requirements ##

SilverStripe 2.4 or newer
'Queued Jobs' module

PHP extension zlib required for PDF2Text class.
PHP extension zip required for newer MS Office document scanning.

For a SilverStripe 3 version, see Graeme Smith's fork at Github:
https://github.com/Instagraeme/Silverstripe3-Lucene


# Installation Instructions #

Check out the archive into the root directory of your project.  This should be
the same folder as the 'sapphire' directory.

Via SVN:
```
svn export http://lucene-silverstripe-plugin.googlecode.com/svn/trunk/ lucene
```

This will create a directory called 'lucene' containing the plugin files.

You will need to have the 'Queued Jobs' module installed in order to use Lucene:

http://www.silverstripe.org/queued-jobs-module/

Run /dev/build?flush=1 to tell your SilverStripe about your new module, and your
new search engine is installed!  (You still need to enable it - see below.)


## QueuedJobs ##

The QueuedJobs module recommends setting your system up to use the
ss\_environment.php config file, and trigger jobs using a cron job that calls
SilverStripe in command line mode.

To get queued jobs to run, you will need to add $_FILE\_TO\_URL\_MAPPING to your_ss\_environment.php file as described in the SilverStripe docs:

http://doc.silverstripe.org/sapphire/en/topics/commandline

This tends to create permissions issues, where the user running the cron job
can't access files indexed by the web user.  It's best to use a cron job using
wget or another tool that will hit the webserver directly, eg:

```
*/1 * * * * wget http://your.server.name/dev/tasks/ProcessJobQueueTask -O /dev/null
```
OR
```
*/1 * * * * curl http://your.server.name/dev/tasks/ProcessJobQueueTask > /dev/null
```

Try the command from the commandline to make sure it will work OK.  If it is
working, you should see something like this:

```
wget -q http://your.server.name/dev/tasks/ProcessJobQueueTask -O -
<h1>Running task 'ProcessJobQueueTask'...</h1>
[2011-07-27 09:34:17] Processing queue 2
[2011-07-27 09:34:17] No new jobs
```
OR
```
curl http://your.server.name/dev/tasks/ProcessJobQueueTask
<h1>Running task 'ProcessJobQueueTask'...</h1>
[2011-07-27 09:35:32] Processing queue 2
[2011-07-27 09:35:32] No new jobs
```

## Third-Party Utility Installation ##

To enable pdf scanning using the pdftotext utility on Linux, ensure that the
command-line utility is installed.  If you are using Debian or Ubuntu, either
of the poppler-utils or xpdf-utils packages will provide this utility:

```
apt-get install poppler-utils
```

If you are on another Linux, Mac OS X, or Windows, the Xpdf program includes
pdftotext:

http://www.foolabs.com/xpdf/

Under Windows, download the Win32 precompiled binary zipfile, and extract the
pdftotext.exe file somewhere.  Now add the following line to your _config.php:_

```
define('PDFTOTEXT_BINARY_LOCATION', 'C:\path to your binary\pdftotext.exe');
```

If you do not have the pdftotext utility installed, Lucene will use the
PHP-based PDF2Text class by Joeri Stegeman instead.  However, this class is
limited in it's ability compared to pdftotext.

Word, Excel and Powerpoint scanning all require the 'zip' PHP module to be
installed.  If you don't have it, newer docx, xlsx and pptx documents won't be
scanned.

To get scanning of older doc, xls and ppt documents working, you need to install
the catdoc command-line utility.  There are Windows and Mac OS X ports also.

http://wagner.pp.ru/~vitus/software/catdoc/

http://blog.brush.co.nz/2009/09/catdoc-windows/

http://catdoc.darwinports.com/

Linux and Mac OS X should work after installation.

Under Windows you will need to tell Lucene where to find the utilities by adding
the following to your _config.php:_

```
define('CATDOC_BINARY_LOCATION', 'C:\path to your binary\catdoc.exe');
define('CATPPT_BINARY_LOCATION', 'C:\path to your binary\catppt.exe');
define('XLS2CSV_BINARY_LOCATION', 'C:\path to your binary\xls2csv.exe');
```

# Quick Start #

If you just want to get up and running as quickly as possible with your Lucene
search engine, install it as per above, and then add the following line to your
project's _config.php file:_

```
ZendSearchLuceneSearchable::enable();
```

Then run a /dev/build?flush=1.

If you're using the Black Candy theme, or another theme that supports the
standard SilverStripe Fulltext Search, your search will now run using Lucene,
indexing all Pages and indexable Files (PDF, Word, Excel, Powerpoint and HTML).

To get the most out of your new search engine, continue reading.


# Configuration Instructions #

## Enabling the search engine ##

By default, the Lucene Search engine is not enabled.  To enable it, you need to
add the following into your _config.php file:_

```
ZendSearchLuceneSearchable::enable();
```

This will configure all SiteTree and File objects by adding the
'ZendSearchLuceneSearchable' extension to those classes.  The following fields
will be indexed whenever an object of this class is written to the database:

```
'SiteTree' => 'Title,MenuTitle,Content,MetaTitle,MetaDescription,MetaKeywords',
'File' => 'Filename,Title,Content'
```

After enabling the search engine, you will need to build the index for the first
time.  There is a new button marked 'Rebuild search index' on the SiteConfig
page, which is the page in the LHS column at the top, with the name of the site.
This will add a new job to the 'Jobs' list - this will give you a readout of how
far through reindexing your site is.

If you just want to get Lucene up and running as quickly as possible, you can
skip down to the 'Usage Overview' section below - that's all the configuration
you need to do!

## Indexing classes ##

If you wish to enable the search engine, but not automatically add the extension
to SiteTree and/or File, pass in an array containing the classes to index:
(this only accepts SiteTree and File, see below for indexing other classes)

```
// Use one of these lines to control which classes to extend
ZendSearchLuceneSearchable::enable(array('SiteTree', 'File'));
ZendSearchLuceneSearchable::enable(array('SiteTree'));
ZendSearchLuceneSearchable::enable(array('File'));

// Do not automatically add the extension to any classes
ZendSearchLuceneSearchable::enable(array());
```

In order to index classes other than the defaults, you need to add the
ZendSearchLuceneSearchable extension with a list of which fields to index.

For instance, to index your custom Page class, which has custom Summary and
Intro fields added:

```
Object::add_extension(
    'Page',
    "ZendSearchLuceneSearchable('"
    ."Title,MenuTitle,MetaTitle,MetaDescription,MetaKeywords,"
    ."Summary,Intro,Content')"
);
```

You can also index custom functions that return strings.  If your indexed object
has a method called 'getFoo()' that returns a string representing some special
state you want to index, adding 'getFoo' into the field list will index this
state.

There are four types of indexing used in Lucene:

1. Keyword - Data that is searchable and stored in the index, but not broken up
into tokens for indexing. This is useful for being able to search on non-textual
data such as IDs or URLs.

2. UnIndexed - Data that isn’t available for searching, but is stored with our
document (eg. article teaser, article URL  and timestamp of creation)

3. UnStored - Data that is available for search, but isn’t stored in the index
in full (eg. the document content)

4. Text – Data that is available for search and is stored in full (eg. title and
author)

The MenuTitle, MetaTitle, MetaDescription and MetaKeywords fields will be
indexed as Unstored.
LastEdited and Created fields will be Unindexed.
ID and ClassName fields will be indexed as Keyword types.
All other fields will be indexed as Unstored.

Internally this module returns DataObjects containing all the data for each
returned result, so there is no need to use the Text type.  Avoiding it will
keep index file size down and improve both indexing and search performance.


## Indexing relations ##

You can index has\_one, has\_many and many\_many relations, using dot notation to
indicate the fields to read on the related object.

If we have a has\_one relation between Page and our custom class Foo, and Foo
has a text field called Bar, we can index it by adding Foo.Bar into the field
list when we add the extension to the Page type:

```
Object::add_extension(
    'Page',
    "ZendSearchLuceneSearchable('"
    ."Title,MenuTitle,MetaTitle,MetaDescription,MetaKeywords,"
    ."Content,Foo.Bar')"
);
```

You can nest relations several layers deep if necessary, eg.
Foo.Bar.Baz.Buz - remember that the names used are the names of the relation
fields, NOT the names of the classes being indexed.

## Indexing files ##

When indexing 'File' DataObjects, this module will detect the file type using
the file extension.  Detected types are .txt, .xls, .doc, .ppt, .xlsx, .docx,
.htm, .html, .pptx, and .pdf.

See the 'Installation' section above for details on getting file scanning
working for various file types.


## Advanced field-level indexing options ##

You can get more fine-grained control over how your classes are indexed by
adding the ZendSearchLuceneSearchable extension with a JSON-encoded object as
the argument.

Your object should be arranged as key-value pairs, the key being the name of the
property, method or relation you wish to index, and the value being another
object containing key-value pairs indicating the options for that field.

```
Object::add_extension(
    'Page',
    "ZendSearchLuceneSearchable('
        {
            \"Title\" : {
                name : \"Title\",
                type : \"text\",
                boost: \"1.5\"
            },
            \"CreatedDate\" : {
                name : \"Date\",
                type : \"text\",
                content_filter : \"strtotime\"
            },
            \"Intro\" : true,
            \"Content\" : {
                name : \"Content\",
                type : \"unstored\"
            },
            \"Foo.Bar\" : {
                name : \"Baz\"
            },
            \"Images\" : {
                content_filter : [\"HelperClass\",\"countImages\"]
            }
        }    
    ')"
);
```

Any omitted config options will use the defaults.  Available config options for
each field are:

  * name
> > The name to store this as in the document.  Default is the same as
> > the field name.  The field name of 'ID' is a special case - this should always
> > use a name of 'ObjectID', as this is used internally.

  * type
> > The type of indexing to use.  Default is "text", legal options are "text",
> > "keyword", "unstored" and "unindexed".

  * boost
> > The importance of this field compared to other fields.  Default boost is 1.
> > Setting this to a higher value will give more importance to a term if it is
> > found in this field.  Useful for making a title field more important than
> > a content field.

  * content\_filter
> > a callback that should be used to transform the field value
> > prior to being indexed.  The callback will be called with one argument,
> > the field value as a string, and should return the transformed field value
> > also as a string.  Could be useful for eg. turning date strings into unix
> > timestamps prior to indexing.  A value of false will indicate that there
> > should be no content filtering, which is the default.


## Advanced class-level indexing options ##

You can also provide a second JSON-encoded argument when initialising a class
using Object::add\_extension.  This should contain key-value pairs indicating
your class-level configuration.

```
Object::add_extension(
    'Foo',
    "ZendSearchLuceneSearchable('Foo,Far,Faz','
        {
            "index_filter" : "\"ID\" IN ( SELECT \"ID\" FROM \"Foo\" LEFT JOIN \"Other\" ON \"Foo\".\"ID\" = \"Other\".\"FooID\" WHERE \"Other\".\"FooID\" IS NOT NULL )"
        }
    ')"
);
```

Currently there is only one configuration option:

  * index\_filter
> > a string to be used as the second argument to DataObject::get() when assembling
> > the list of items of this class to index.  The default is an empty string,
> > which will get all items of that class.

Note that the config can get a bit messy with all the nested escaped quotes.
You may prefer to create PHP objects, json encode them and insert them that way:

```
$fields = array(
    'Foo' => array(
        'name' => 'Foo',
    ),
    'Bar' => array(
        'name' => 'Bar',
        'type' => 'unstored',
        'content_filter' => array('HelperClass','filterFunction')
    )
);
$class = array(
    'index_filter' => '
    "ID" IN ( 
        SELECT "ID" 
        FROM "Foo" 
            LEFT JOIN "Other" 
            ON "Foo"."ID" = "Other"."FooID" 
        WHERE "Other"."FooID" IS NOT NULL 
    )'
);
Object::add_extension(
    'Foo', 
    "'".json_encode($fields)."', '".json_encode($class)."'"
);
```

## Testing the configuration ##

By visiting /Lucene/diagnose, you will get a full description of whether any of
the optional commandline utilities are installed, and a readout of your config
options.

This can help you narrow down any configuration oddities.


## Rebuilding the search engine ##

The search index is rebuilt on every /dev/build.  In case you want to disable
this, for example if your site is quite large and rebuilding the search index
takes a while, you can add the following to your _config.php:_

```
ZendSearchLuceneSearchable::$reindexOnDevBuild = false;
```

To manually rebuild the search index, go to the SiteConfig page (at the very
top of the LHS site tree in the CMS, with the world icon) and there will be a
'Rebuild Search Index' button at the bottom of the page.  Clicking this button
will start a Queued Job, which deletes the current index, scans the site for all
content which should be indexed, and reindexes everything.

You can view reindex progress on the 'Jobs' tab, at the top of the CMS.  It will
display when the job was started, how long it has run for, how many items there
are to be indexed, and how many have been indexed so far.  If there are any
errors, these will also show up here.

You can also visit the URL /Lucene/reindex which will trigger a reindex with
some output.  This will allow you to spot any performance bottlenecks.  Objects
are output as they are indexed in real time.  Note that this isn't suitable for

## Pagination ##

There are some pagination settings that allow you to control the pagination
functions:  (Put these in your _config.php to change them)_

```
// Number of results to show on each page
ZendSearchLuceneSearchable::$pageLength = 10;

// Maximum number of pages to show in the pagination
ZendSearchLuceneSearchable::$maxShowPages = 10;

// Always show this number of pages at the start of the pagination
ZendSearchLuceneSearchable::$alwaysShowPages = 3;
```

## Index directory ##

You can also set where to store the index:

```
// These are the defaults.
ZendSearchLuceneSearchable::$cacheDirectory = TEMP_FOLDER;
ZendSearchLuceneWrapper::$indexName = 'Lucene';
```

With the default settings, the index will be created in the SilverStripe temp
folder, and will be called 'Lucene'.


## Advanced index configuration ##

http://zendframework.com/manual/en/zend.search.lucene.index-creation.html#zend.search.lucene.index-creation.optimization

You can use advanced configuration functions directly on the index:

```
$index = ZendSearchLuceneWrapper::getIndex();

// Retrieving index size
$indexSize = $index->count();
$documents = $index->numDocs();

// Index optimisation
$index->optimize();
```

You can also specify operations to be run on newly created indexes using
ZendSearchLuceneWrapper::addCreateIndexCallback().  On creation, any callbacks
registered using this function are run.  This allows you to set up any
optimisation options you require on your index.  The Zend defaults are used if
no callbacks are registered.

To use a callback, you can put something like this in your _config.php:_

```
function create_index_callback() {
    $index = ZendSearchLuceneWrapper::getIndex();
    $index->setMaxBufferedDocs(20);
}
ZendSearchLuceneWrapper::addCreateIndexCallback('create_index_callback');
```

# Usage Overview #

Once you have configured and enabled the plugin, you can add a new token into
your template files to output the search form:

```
<!-- START search form -->
$ZendSearchLuceneForm
<!-- END search form -->
```

This will post to the action ZendSearchLuceneResults, which will display the
Search Results page.

This module will also take over the $SearchForm token - this is for convenience,
to get users up and running quickly using the out-of-the-box themes.  If you're
planning on customising the form markup, use $ZendSearchLuceneForm instead.


## Custom search form ##

To customise your search form, override this method (or create a new one) and
output a Form object containing a field called 'Search' and an action of
ZendSearchLuceneResults.

```
/* Custom search form */
class Your_Controller extends Page_Controller {

   // . . .

   function ZendSearchLuceneForm() {
      $form = parent::ZendSearchLuceneForm();
      // Customise the form
      return $form;
   }

}
```

If you are using $ZendSearchLuceneForm in your templates, you can create a
custom template for the search form called ZendSearchLuceneForm.ss - it can go
in either your root template folder, or in your Includes/ folder.  Copying
sapphire/templates/SearchForm.ss is a good starting point.


## Custom search results page ##

In the templates/Layout folder of the plugin, you will find the
Lucene\_results.ss file.  Copy this file into your own theme's Layout folder, and
alter to your heart's content.

Available templating tokens in this file are:

```
$Query - The string that was searched for
$TotalResults - Total number of hits for the search
$TotalPages - Total number of pages for the query
$ThisPage - The page number currently being viewed
$StartResult - The number of the first result on this page
$EndResult - The number of the last result on this page
$PrevUrl - URL to the previous page of search results
$NextUrl - URL to the next page of results

<% control Results %>
  <!-- DataObjectSet containing the search results for the current page -->
  $score (relevance rating assigned by the search engine)
  $Number (which number in the set this result is)
  $Link (URL to this resource)
  You can also use any fields that have been indexed, eg. $Content
<% end_control %>

<% control SearchPages %>
  <!-- This is a DataObjectSet containing the pagination pages -->
  $IsEllipsis  (whether this entry is a blank ellipsis to indicate more pages)
  $PageNumber
  $Link  (URL to this page of search results)
  $Current   (Boolean indicating whether this is the current page)
  $Start  (use this as the URL parameter start to get to this page)
<% end_control %>  
```

A useful extra function is the SearchTextHighlight string modifier.  If you use
eg. $Content.SearchTextHighlight in your template, this will output an HTML
paragraph containing 25 words surrounding your search terms, with the search
terms highlighted with strong tags.

This modifier takes one optional argument, the number of words to display.  So
to display a 50 word summary you would use:

```
$Content.SearchTextHighlight(50) 
```

## Custom search function ##

Lucene is actually a very powerful search engine, you can do a lot with it.  If
you have a more advanced search function you want to implement, you can build
your own form and submit it to your own action.  Check the Zend docs on building
queries for how to build the query you want from the form fields you've
received.

http://zendframework.com/manual/en/zend.search.lucene.searching.html

```
class Your_Controller extends Page_Controller {

    /**
     * Use $AdvancedSearchForm in your template to output this form.
     */
    function AdvancedSearchForm() {
        $fields = new FieldSet(
            new TextField('Query','First search query'),
            new TextField('Subquery', 'Second search query')
        );
        $actions = new FieldSet(
            new FormAction('AdvancedSearchResults', 'Search')
        );
        $form = new Form($this->owner, 'AdvancedSearchForm', $fields, $actions);
        $form->disableSecurityToken();
        return $form;
    }

    /**
     * Processes the search form
     */
    function AdvancedSearchResults($data, $form, $request) {
        // Assemble your custom query 
        $query = Zend_Search_Lucene_Search_QueryParser::parse(
            $form->dataFieldByName('Query')->dataValue()
        );
        $subquery = Zend_Search_Lucene_Search_QueryParser::parse(
            $form->dataFieldByName('Subquery')->dataValue()
        );
        $search = new Zend_Search_Lucene_Search_Query_Boolean();
        $search->addSubquery($query, true);
        $search->addSubquery($subquery, false);

        // Get hits from the Lucene search engine.
        $hits = ZendSearchLuceneWrapper::find($search);

        // Convert these into a data array containing pagination info etc
        $data = $this->getDataArrayFromHits($hits, $request);

        // Display the results page
        return $this->owner->customise($data)->renderWith(array('Advanced_results', 'Page'));
    }

}
```

# TODO #

  * Allow the use of multiple indexes per project
  * Query logging
  * Add a language file - text strings are already translatable
  * Make text highlighter more configurable.


# Links #

  * wpSearch plugin for WordPress
> > http://codefury.net/projects/wpSearch/

  * Zend\_Search\_Lucene documentation
> > http://zendframework.com/manual/en/zend.search.lucene.html

  * Queued Jobs module
> > http://www.silverstripe.org/queued-jobs-module/

  * Xpdf (pdftotext PDF text extraction utility)
> > http://www.foolabs.com/xpdf/

  * catdoc (MS Office text extraction utility)
> > http://wagner.pp.ru/~vitus/software/catdoc/ <br />
> > http://blog.brush.co.nz/2009/09/catdoc-windows/ <br />
> > http://catdoc.darwinports.com/
