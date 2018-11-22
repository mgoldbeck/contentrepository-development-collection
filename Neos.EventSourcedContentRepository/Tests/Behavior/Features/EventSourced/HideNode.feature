@fixtures
Feature: Single Node operations on live workspace

  As a user of the CR I want to execute operations on a node in live workspace.

  Background:
    Given I have no content dimensions
    And the command CreateWorkspace is executed with payload:
      | Key                      | Value                                | Type |
      | workspaceName            | live                                 |      |
      | contentStreamIdentifier  | cs-identifier                        | Uuid |
      | rootNodeIdentifier       | rn-identifier                        | Uuid |
    And I have the following NodeTypes configuration:
    """
    'Neos.ContentRepository.Testing:Content':
      properties:
        text:
          type: string
    """
    And the Event NodeAggregateWithNodeWasCreated was published with payload:
      | Key                           | Value                                  | Type                   |
      | contentStreamIdentifier       | cs-identifier                          | Uuid                   |
      | nodeAggregateIdentifier       | na-identifier                          | Uuid                   |
      | nodeTypeName                  | Neos.ContentRepository.Testing:Content |                        |
      | nodeIdentifier                | node-identifier                        | Uuid                   |
      | parentNodeIdentifier          | rn-identifier                          | Uuid                   |
      | nodeName                      | text1                                  |                        |

    And the Event NodeAggregateWithNodeWasCreated was published with payload:
      | Key                     | Value                                  | Type |
      | contentStreamIdentifier | cs-identifier                          | Uuid |
      | nodeAggregateIdentifier | cna-identifier                         | Uuid |
      | nodeTypeName            | Neos.ContentRepository.Testing:Content |      |
      | nodeIdentifier          | cnode-identifier                       | Uuid |
      | parentNodeIdentifier    | node-identifier                        | Uuid |
      | nodeName                | text2                                  |      |



  Scenario: Hide a node
    Given the command "HideNode" is executed with payload:
      | Key                          | Value         | Type |
      | contentStreamIdentifier      | cs-identifier | Uuid |
      | nodeAggregateIdentifier      | na-identifier | Uuid |
      | affectedDimensionSpacePoints | [{}]          | json |

    Then I expect exactly 5 events to be published on stream with prefix "Neos.ContentRepository:ContentStream:[cs-identifier]"
    And event at index 4 is of type "Neos.EventSourcedContentRepository:NodeWasHidden" with payload:
      | Key                          | Expected      | Type | AssertionType |
      | contentStreamIdentifier      | cs-identifier | Uuid |               |
      | nodeAggregateIdentifier      | na-identifier | Uuid |               |
      | affectedDimensionSpacePoints | [{}]          |      | json          |

    And the graph projection is fully up to date

    When I am in the active content stream of workspace "live" and Dimension Space Point {}
    Then I expect a node "[node-identifier]" not to exist in the graph projection

    When ContextParameters are set to:
      | Key                   | Value |
      | invisibleContentShown | true  |
    Then I expect a node "[node-identifier]" to exist in the graph projection

  Scenario: Hide a non-existing node should throw an exception
    Given the command "HideNode" is executed with payload and exceptions are caught:
      | Key                          | Value              | Type |
      | contentStreamIdentifier      | cs-identifier      | Uuid |
      | nodeAggregateIdentifier      | unknown-identifier | Uuid |
      | affectedDimensionSpacePoints | [{}]               | json |
    Then the last command should have thrown an exception of type "NodeNotFoundException"

  Scenario: Hide a non-existing node in a certain dimension should throw an exception
    Given the command "HideNode" is executed with payload and exceptions are caught:
      | Key                          | Value                | Type |
      | contentStreamIdentifier      | cs-identifier        | Uuid |
      | nodeAggregateIdentifier      | na-identifier        | Uuid |
      | affectedDimensionSpacePoints | [{"language": "de"}] | json |
    Then the last command should have thrown an exception of type "NodeNotFoundException"