# SearchWordsSQL
Converts a Google-style query expression of seach words into an SQL boolean expression and a MySQL IBL (a.k.a. 'Implied Boolean Logic' or 'IN BOOLEAN MODE') one for full-text search.

## Quickstart
To begin with, put SearchWordsSQL.php on the PHP library path.

If the database server supports an SQL LIKE operator for text search, your code would be like:
```php
require_once 'SearchWordsSQL.php';
$searchwords = "php library OR code";

$sqlbuilder = new SearchWordsSQL\SQLBuilder("BodyText LIKE ?", "\SearchWordsSQL\SQLLikeValueCallback");
    // The first argument is a parameterized SQL boolean expression for each word
    // The second specifies the values should be converted for a LIKE operator.
$result = $sqlbuilder->Build($searchwords);
echo $result['SQL'] . "\n";
    // produces '((BodyText LIKE ?) AND ( (BodyText LIKE ?) OR (BodyText LIKE ?) ) )'
echo join(", ", $result['value']) . "\n";
    // produces '%php%, %library%, %code%'
    
$db = new PDO("pgsql:host=localhost");
$stmt = $db->prepare("SELECT * FROM BookText WHERE ${result['SQL']}");
$stmt->execute($result['value']);
	// execute it with the returned values as the parameters
```

If you would like to utilize MySQL IBL:
```php
require_once 'SearchWordsSQL.php';
$searchwords = "php library OR code";

$sqlbuilder = new SearchWordsSQL\SQLBuilder("");
	// We don't want an SQL expression
$result = $sqlbuilder->Build($searchwords);
echo $result['IBL'] . "\n";
	// produces '+php (library code)'
    
$db = new PDO("mysql:host=localhost");
$stmt = $db->prepare("SELECT * FROM BookText WHERE MATCH (BodyText) AGAINST (? IN BOOLEAN MODE)");
$stmt->execute(array($result['IBL']));
```

## Syntax supported for search words
* Words separated by space(s) means all of them should be included in the text to be searched in.
* Words separated by ` OR ` in upper case means at least one of them should be included.
* A pair of parenthesis `()` groups expression inside.
* `a b OR c d` means `a (b OR c) d`.
* A word following minus sign `-` means it must not be included.
* A pair of double quotation marks `""` specifies words inside containing spaces to be treated as a word. You can escape with a backslash.
* `*` inside a word means 'any characters.' Bare star(s) are not allowed.

## API Documentation
Run makedoc.sh and see ./doc/index.html. Also there are [online documents](http://yonaka.github.io/SearchWordsSQL/doc/).

## License
LGPLv2 or later. See COPYING.LESSER.

## Caveats
* This library does not comform to naming convention of standard PHP codes.
* IBL expression the library produces might be too complex for MySQL to interpret.

## Changelog
### v0.2.1
* Changed the license from LGPLv3 to LGPLv2.1 or later.

### v0.2
* Added support for prefix functions.

### v0.1
* Initial Release.

