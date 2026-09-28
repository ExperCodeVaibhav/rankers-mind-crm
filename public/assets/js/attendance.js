(() => {
  'use strict';
  const video = document.getElementById('attendanceVideo'); if (!video) return;
  const frame = document.getElementById('cameraFrame'); const canvas = document.getElementById('attendanceCanvas');
  const startButton = document.getElementById('startCamera'); const captureButton = document.getElementById('capturePhoto'); const submitButton = document.getElementById('submitPunch');
  let photo = ''; let location = null; let stream = null;
  const updateReady = () => { submitButton.disabled = !(photo && location); };
  startButton.addEventListener('click', async () => {
    if (!navigator.mediaDevices?.getUserMedia) return window.rmToast('Camera access requires a supported browser and HTTPS after deployment.', 'error');
    try { stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:960},height:{ideal:720}},audio:false}); video.srcObject = stream; frame.classList.add('active'); document.getElementById('cameraPlaceholder').hidden = true; captureButton.disabled = false; startButton.innerHTML = '<i class="fa-solid fa-rotate"></i> Restart camera'; }
    catch (error) { window.rmToast('Camera permission was denied or no camera is available.', 'error'); }
  });
  captureButton.addEventListener('click', () => {
    if (!video.videoWidth) return; const max = 960; const ratio = Math.min(1, max / video.videoWidth); canvas.width = Math.round(video.videoWidth * ratio); canvas.height = Math.round(video.videoHeight * ratio); const context = canvas.getContext('2d'); context.drawImage(video, 0, 0, canvas.width, canvas.height); photo = canvas.toDataURL('image/jpeg', .72); document.getElementById('captureStatus').classList.add('done'); document.getElementById('captureStatus').innerHTML = '<i class="fa-solid fa-circle-check"></i> Live photo captured'; captureButton.innerHTML = '<i class="fa-solid fa-camera-rotate"></i> Retake photo'; updateReady();
  });
  document.getElementById('getLocation').addEventListener('click', () => {
    if (!navigator.geolocation) return window.rmToast('Geolocation is not supported by this browser.', 'error');
    const status = document.getElementById('locationStatus'); status.textContent = 'Locating with high accuracy…';
    navigator.geolocation.getCurrentPosition(position => { location = {latitude:position.coords.latitude,longitude:position.coords.longitude,accuracy:position.coords.accuracy}; document.getElementById('latitudeValue').textContent = location.latitude.toFixed(6); document.getElementById('longitudeValue').textContent = location.longitude.toFixed(6); document.getElementById('accuracyValue').textContent = `±${Math.round(location.accuracy)} m`; status.textContent = `Location acquired (±${Math.round(location.accuracy)} m)`; updateReady(); }, error => { status.textContent = 'Location permission or signal unavailable'; window.rmToast(error.message || 'Location could not be acquired.', 'error'); }, {enableHighAccuracy:true,timeout:15000,maximumAge:0});
  });
  submitButton.addEventListener('click', async () => {
    if (!photo || !location) return; submitButton.disabled = true; submitButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Recording server time…';
    const body = new URLSearchParams({_token:window.RM.csrf,photo,...Object.fromEntries(Object.entries(location).map(([k,v])=>[k,String(v)]))});
    try { const response = await fetch(`${window.RM.baseUrl}/attendance/punch`,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json','X-CSRF-Token':window.RM.csrf},body}); const data = await response.json(); if (!response.ok || !data.ok) throw new Error(data.message || 'Attendance could not be recorded.'); stream?.getTracks().forEach(track=>track.stop()); window.rmToast(data.message); setTimeout(()=>location.reload(),900); }
    catch (error) { window.rmToast(error.message,'error'); submitButton.disabled=false; submitButton.innerHTML='<i class="fa-solid fa-fingerprint"></i> Try punch again'; }
  });
})();
