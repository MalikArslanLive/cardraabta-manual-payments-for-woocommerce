(function() {
    const registry=window.wc && window.wc.wcBlocksRegistry,
        settings=window.wc && window.wc.wcSettings,
        element=window.wp && window.wp.element;
    if (!registry || !settings || !element) return;
    const h=element.createElement,{useState,useEffect,useRef}=element,
        data=settings.getSetting('crmp_manual_data',{});
    if (!data.methods || !data.methods.length) return;
    const requireProof=data.require_proof!==false;
    const policy=Object.assign({screenshot_enabled:true,screenshot_required:true,reference_enabled:false,reference_required:false,date_enabled:true,date_required:true,note_enabled:false,note_required:false,confirmation_enabled:false,confirmation_required:false},data.proof_policy || {});
    const Content=function(props) {
        const [slug,setSlug]=useState(data.methods[0].slug),[reference,setReference]=useState(''),
            [paymentDate,setPaymentDate]=useState(''),[note,setNote]=useState(''),[confirmed,setConfirmed]=useState(false),
            [fileData,setFileData]=useState(''),[fileName,setFileName]=useState(''),
            [fileError,setFileError]=useState(''),[reading,setReading]=useState(false),[readProgress,setReadProgress]=useState(null);
        const readerRef=useRef(null),method=data.methods.find(item=>item.slug===slug) || data.methods[0];
        useEffect(()=>()=>{readerRef.current?.abort();readerRef.current=null;},[]);
        useEffect(()=>{
            const events=props.eventRegistration;
            if (!events) return;
            const subscribe=events.onPaymentSetup || events.onPaymentProcessing;
            if (!subscribe) return;
            return subscribe.call(events,()=>{
                const types=props.emitResponse.responseTypes,error=message=>({type:types.ERROR,message});
                const attempt=fileData || fileError || reading || (policy.reference_enabled&&reference.trim()) || (policy.date_enabled&&paymentDate) || (policy.note_enabled&&note.trim()) || (policy.confirmation_enabled&&confirmed);
                if(!requireProof&&!attempt)return {type:types.SUCCESS,meta:{paymentMethodData:{crmp_selected_method:slug,crmp_transaction_ref:'',crmp_screenshot_data:'',crmp_payment_date:'',crmp_customer_note:'',crmp_proof_confirm:''}}};
                if (fileError || reading) return error(fileError || 'Please wait for the screenshot to finish loading.');
                if (policy.screenshot_required && !fileData) return error('Upload your payment screenshot before submitting.');
                if (policy.reference_required && !reference.trim()) return error('Enter your transaction reference before submitting.');
                if (!reference.trim() && !fileData) return error('Provide a transaction reference or payment screenshot before placing your order.');
                if (policy.date_required && !paymentDate) return error('Select the date you made this payment.');
                if (policy.note_required && !note.trim()) return error('Enter the additional payment note.');
                if (policy.confirmation_required && !confirmed) return error('Confirm that the payment details are accurate and you have already paid.');
                return {type:types.SUCCESS,meta:{paymentMethodData:{
                    crmp_selected_method:slug,
                    crmp_transaction_ref:policy.reference_enabled?reference:'',
                    crmp_screenshot_data:policy.screenshot_enabled?fileData:'',
                    crmp_payment_date:policy.date_enabled?paymentDate:'',
                    crmp_customer_note:policy.note_enabled?note:'',
                    crmp_proof_confirm:policy.confirmation_enabled && confirmed?'1':''
                }}};
            });
        },[slug,reference,paymentDate,note,confirmed,fileData,fileError,reading,props.eventRegistration,props.emitResponse]);
        function selectFile(event) {
            const file=event.target.files && event.target.files[0];
            readerRef.current?.abort();readerRef.current=null;
            setFileData('');setFileError('');setFileName('');setReading(false);setReadProgress(null);
            if (!file) return;
            if (!['image/png','image/jpeg','image/webp'].includes(file.type) || file.size>(data.max_upload_bytes || 5*1024*1024)) {
                setFileError('Use JPG, PNG or WebP within the configured size limit.');return;
            }
            const reader=new FileReader();readerRef.current=reader;setFileName(file.name);setReading(true);
            reader.onprogress=event=>{if(readerRef.current===reader)setReadProgress(event.lengthComputable && event.total?event.loaded/event.total*100:null);};
            reader.onload=()=>{if(readerRef.current!==reader)return;setFileData(reader.result);setReading(false);};
            reader.onerror=()=>{if(readerRef.current!==reader)return;setReading(false);setFileError('Unable to read the screenshot. Select it again.');};
            reader.readAsDataURL(file);
        }
        const attempt=fileData || fileError || reading || (policy.reference_enabled&&reference.trim()) || (policy.date_enabled&&paymentDate) || (policy.note_enabled&&note.trim()) || (policy.confirmation_enabled&&confirmed),enforce=requireProof||!!attempt;
        const required=key=>policy[key+'_required']?(requireProof?' (required)':' (required with proof)'):' (optional)';
        const hint=policy.screenshot_required && policy.reference_required?'A screenshot and transaction reference are both required.':policy.screenshot_required?'Upload your payment screenshot before placing your order.':policy.reference_required?'Enter your transaction reference before placing your order.':'Provide a screenshot, transaction reference, or both before placing your order.';
        return h('div',{className:'crmp-block','data-proof-policy':JSON.stringify(policy)},
            h('p',null,data.description),
            h('label',null,'Select payment method',h('select',{value:slug,onChange:event=>setSlug(event.target.value)},data.methods.map(item=>h('option',{key:item.slug,value:item.slug},item.title)))),
            h('div',{className:'crmp-method-preview'},
                h('div',{className:'crmp-receiving-field'},h('span',{className:'crmp-receiving-label'},'Account title'),h('strong',null,method.account_title)),
                h('div',{className:'crmp-receiving-field'},h('span',{className:'crmp-receiving-label'},'Account number / IBAN'),h('p',{style:{overflowWrap:'anywhere'}},method.account)),
                method.qr_url?h('div',null,h('span',{className:'crmp-receiving-label'},'Scan QR code to pay'),h('img',{src:method.qr_url,alt:method.title+' payment QR code',style:{maxWidth:'100%',width:'140px',height:'auto'}})):null,
                h('p',null,method.instructions)),
            h('div',{className:'crmp-checkout-proof'},h('h3',null,requireProof?'Payment proof (required before placing the order)':'Payment proof (optional now; submit later)'),
                policy.reference_enabled?h('label',null,'Transaction reference'+required('reference'),h('input',{name:'crmp_transaction_ref',type:'text',value:reference,maxLength:191,required:enforce&&policy.reference_required,placeholder:'Enter reference',onChange:event=>setReference(event.target.value)})):null,
                policy.date_enabled?h('label',null,'Payment date'+required('date'),h('input',{name:'crmp_payment_date',type:'date',value:paymentDate,max:data.payment_date_max,required:enforce&&policy.date_required,onChange:event=>setPaymentDate(event.target.value)})):null,
                policy.screenshot_enabled?h('div',{className:'crmp-screenshot-field'},h('label',{className:'crmp-proof-upload'},h('strong',null,'Payment screenshot'+required('screenshot')),h('span',{className:'crmp-upload-surface'},
                    h('span',{className:'crmp-upload-icon','aria-hidden':true},'↑'),h('span',{className:'crmp-upload-name'},fileName || 'Choose payment screenshot'),
                    h('small',null,'JPG, PNG or WebP · Max '+Math.round((data.max_upload_bytes || 5*1024*1024)/1024/1024)+' MB'),
                    h('input',{name:'crmp_screenshot',type:'file',accept:'image/jpeg,image/png,image/webp',required:enforce&&policy.screenshot_required,onChange:selectFile}))),
                    h('div',{className:'crmp-upload-feedback',role:fileError?'alert':'status','aria-live':fileError?'assertive':'polite',hidden:!reading&&!fileName&&!fileError,'data-state':fileError?'error':reading?'preparing':'ready'},
                        h('span',{className:'crmp-upload-status'},fileError || (reading?'Preparing screenshot…'+(readProgress!==null?' '+Math.round(readProgress)+'%':''):'Ready to submit: '+fileName+'. Your screenshot will be submitted with your order.')),
                        reading?h('progress',{className:'crmp-upload-progress',max:100,value:readProgress===null?undefined:readProgress,'aria-label':'Screenshot preparation progress'}):null)):null,
                policy.note_enabled?h('label',null,'Additional note'+required('note'),h('textarea',{name:'crmp_customer_note',value:note,maxLength:2000,rows:3,required:enforce&&policy.note_required,placeholder:'Add details that may help us verify your payment',onChange:event=>setNote(event.target.value)})):null,
                policy.confirmation_enabled?h('label',{className:'crmp-proof-confirm'},h('input',{name:'crmp_proof_confirm',type:'checkbox',checked:confirmed,required:enforce&&policy.confirmation_required,onChange:event=>setConfirmed(event.target.checked)}),h('span',null,'I confirm that these payment details are accurate and I have already made this payment.')):null,
                h('p',{className:'crmp-hint'},(requireProof?'':'When submitting proof now: ')+hint+(requireProof?'':' You may leave all proof fields empty and submit proof later. Required fields apply when submitting proof now.')),
                h('p',{className:'crmp-checkout-disclaimer'},'Already paid? Do not pay again. All payment proof is reviewed manually.')));
    };
    registry.registerPaymentMethod({name:'crmp_manual',label:data.title,ariaLabel:data.title,content:h(Content),edit:h(Content),canMakePayment:()=>true,supports:{features:['products']}});
})();
