<script type="text/javascript">
window.metrilo||(window.metrilo=[]),window.metrilo.q=[],mth=["identify","track","event","pageview","purchase","debug","atr"],sk=function(e){return function(){a=Array.prototype.slice.call(arguments);a.unshift(e);window.metrilo.q.push(a)}};for(var i=0;mth.length>i;i++){window.metrilo[mth[i]]=sk(mth[i])}window.metrilo.load=function(e){var t=document,n=t.getElementsByTagName("script")[0],r=t.createElement("script");r.type="text/javascript";r.async=true;r.src="//t.metrilo.com/j/"+e+".js";n.parentNode.insertBefore(r,n)};
window.metrilo.ensure_cbuid = "<?php echo $this->cbuid ?>";
<?php if(!empty($this->api_key) && $this->accept_tracking): ?>
metrilo.load("<?php echo $this->api_key ?>");
<?php endif ?>
</script>