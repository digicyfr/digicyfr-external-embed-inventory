<?php
/** Force a Polish site locale for the disposable WordPress contract. */
add_filter( 'locale', static fn () => 'pl_PL' );
