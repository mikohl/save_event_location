# save_event_location
Custom Drupal module that allows users to easily save and reuse addresses.

The client holds events at varying locations. Their event content type includes
an event address field. Client requested a way to easily save and re-enter
commonly used addresses. Additionally, the address values could not simply be
a referenced entity, because they did not want changes to an event location to
change the content of existing or previous events.

For this I created a new content type, "event_location". The module adds new
fields to the Event content type: a select list titled "Previously used
location", and a checkbox titled "Remember this address?". If the user enters
an address and checks the "Remember.." checkbox, a new event_location node is
saved with the address. The "Previously used..." select list is populated with
address information from event_location nodes that have previously been saved.
If a user selects an address from the "Previously used..." list, an ajax call
updates the address fields in the Event form with the address data.

The event_location content was hidden using the Rabbithole module. Previously
saved event_location nodes could be deleted or edited in a maintenance page
created with a View.