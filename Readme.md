# PunktDe.Elastic.NodeSearchService

[![Latest Stable Version](https://poser.pugx.org/punktde/elastic-nodesearchservice/v/stable)](https://packagist.org/packages/punktde/elastic-nodesearchservice) [![Total Downloads](https://poser.pugx.org/punktde/elastic-nodesearchservice/downloads)](https://packagist.org/packages/punktde/elastic-nodesearchservice)

This is an implementation of the Neos NodeSearchService using the Elasticsearch index of the content repository. This vastly reduces the query time for Search-As-You-Type fields in the backend if you have lots of nodes in your project. Additionally it is highly customizable in order to get the best search experience for the project. 

Multiple search strategies can be defined which are then selected according to the SearchNodeType, StartingPoint and the term. With this feature you are able to sort news documents returned in a reference selector by publish date while other documents are sorted alphabetically.  

Note: While the original database search does a like search in all properties of the document, the default strategy of this package only does a prefix search in the title field. Replace it with the search strategy that fit your needs.

The following example shows a reference selector for news articles with 23 000 Documents.

![Example](Documentation/elastic-vs-db.gif)

## Installation

The installation is done with composer:

	composer require punktde/elastic-nodesearchservice
	
Compatibility to [Flowpack.ElasticSearch.ContentRepositoryAdaptor](https://github.com/Flowpack/Flowpack.ElasticSearch.ContentRepositoryAdaptor):

|Elastic.NodeSearchService| ElasticSearch.ContentRepositoryAdaptor          |
|----------|---------------|
| 1        | 5.x, 6.x      |
| 2        | 7.x    |

## Configuration

### Example

This example uses a multi_match prefix query to search in the `punktde_node_search` field indexed for documents. 

	PunktDe:
	  Elastic:
	    NodeSearchService:
	      logRequests: true
	      searchStrategies:
	        titlePrefix:
	          condition: '${Array.indexOf(searchNodeTypes, "Neos.Neos:Document")}'
	          request:
	            query:
	              bool:
	                filter:
	                  bool:
	                    minimum_should_match: 1
	                    should:
	                      - multi_match:
	                          query: ARGUMENT_TERM
	                          type: bool_prefix
	                          fields: ['punktde_node_search', 'punktde_node_search._2gram', 'punktde_node_search._3gram']
	                    must:
	                      - terms:
	                          __typeAndSupertypes: ARGUMENT_SEARCHNODETYPES
	                      - term:
	                          __parentPath: ARGUMENT_STARTINGPOINT
	                    must_not:
	                      - term:
	                          _hidden: true
	            _source:
	              - __path
	            size: 20




The `condition` is an Eel query, which can be parametrized by the following values. It is used to determine the search strategy to be used. 
If no search strategy could be found, it falls back to database search.

| ParameterName            | Description                            |
|--------------------------|----------------------------------------|
| *string* `term`           | The search term                       |
| *array* `searchNodeTypes` | Array of the search nodetypes         |
| *Context* `context`   | The given node context                    |
| *NodeInterface* `startingPoint`   | The defined starting point    |


These following parameters can be used in the search request to parametrice the query:

| ParameterName            | Description                            |
|--------------------------|----------------------------------------|
| ARGUMENT_SEARCHNODETYPES | Array_Values of the filter NodeTypes.  |
| ARGUMENT_TERM            | The Searchterm                         |
| ARGUMENT_STARTINGPOINT   | The startingPoint path                 |


